<?php
declare(strict_types=1);

namespace Panel\Api;

use Panel\Forms\FormService;
use Panel\Http\ApiError;
use Panel\Http\Request;
use Panel\Http\Response;
use Panel\Http\Router;
use Panel\Mail\Message;
use Panel\Mail\Smtp;
use Panel\Mail\SmtpError;
use Panel\Publish\Publisher;
use Panel\Store;

/** SMTP del sito, configurazione dei moduli e storico dei messaggi ricevuti. */
final class FormsApi extends Api
{
    private const BREVO = ['host' => 'smtp-relay.brevo.com', 'port' => 587, 'encryption' => 'tls'];

    public function __construct(Store $store, Publisher $publisher, array $principal, private FormService $forms)
    {
        parent::__construct($store, $publisher, $principal);
    }

    public function routes(Router $r): void
    {
        $r->add('GET', '/sites/{site}/mail', fn(Request $q) => $this->mail($q));
        $r->add('PUT', '/sites/{site}/mail', fn(Request $q) => $this->saveMail($q));
        $r->add('POST', '/sites/{site}/mail/test', fn(Request $q) => $this->testMail($q));

        $r->add('GET', '/sites/{site}/forms', fn(Request $q) => Response::json(['data' => $this->forms->forms($this->site($q->param('site'))['id'])]));
        $r->add('GET', '/sites/{site}/forms/{key}', fn(Request $q) => Response::json(['data' => $this->forms->form($this->site($q->param('site'))['id'], $q->param('key'))]));
        $r->add('PATCH', '/sites/{site}/forms/{key}', fn(Request $q) => $this->updateForm($q));

        $r->add('GET', '/sites/{site}/submissions', fn(Request $q) => $this->submissions($q));
        $r->add('GET', '/sites/{site}/submissions/{id}', fn(Request $q) => Response::json(['data' => $this->forms->submission($this->site($q->param('site'))['id'], (int) $q->param('id'))]));
        $r->add('POST', '/sites/{site}/submissions/{id}/resend', fn(Request $q) => $this->resend($q));
        $r->add('DELETE', '/sites/{site}/submissions/{id}', fn(Request $q) => $this->deleteSubmission($q));
    }

    // ---------------------------------------------------------------- SMTP

    private function mail(Request $req): Response
    {
        $site = $this->site($req->param('site'));
        return Response::json(['data' => $this->forms->mailSettings($site['id'])]);
    }

    /**
     * PUT: configurazione completa. "preset": "brevo" compila host/porta/cifratura.
     * La password non viene mai restituita: se omessa resta quella salvata.
     */
    private function saveMail(Request $req): Response
    {
        $site = $this->site($req->param('site'));
        $in = $req->input();
        if (($in['preset'] ?? null) === 'brevo') {
            $in += self::BREVO;
        }
        $errors = [];
        $s = [
            'host'       => trim((string) ($in['host'] ?? '')),
            'port'       => (int) ($in['port'] ?? 0),
            'encryption' => (string) ($in['encryption'] ?? 'tls'),
            'username'   => trim((string) ($in['username'] ?? '')),
            'from_email' => trim((string) ($in['from_email'] ?? '')),
            'from_name'  => trim(strip_tags((string) ($in['from_name'] ?? ''))),
            'recipients' => is_array($in['recipients'] ?? null) ? array_values(array_unique(array_map('trim', $in['recipients']))) : [],
        ];
        if (array_key_exists('password', $in)) {
            $s['password'] = (string) $in['password'];
        }

        if (!preg_match('~^[a-z0-9.-]+$~i', $s['host'])) {
            $errors['host'] = 'Obbligatorio, es. smtp-relay.brevo.com (oppure "preset": "brevo")';
        }
        if ($s['port'] < 1 || $s['port'] > 65535) {
            $errors['port'] = 'Porta non valida (Brevo: 587)';
        }
        if (!in_array($s['encryption'], ['tls', 'ssl', 'none'], true)) {
            $errors['encryption'] = 'Valori ammessi: tls (STARTTLS), ssl, none';
        }
        if (!filter_var($s['from_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['from_email'] = 'Email mittente non valida (es. noreply@dominio-cliente.it)';
        }
        if (!$s['recipients'] || array_filter($s['recipients'], fn($e) => !filter_var($e, FILTER_VALIDATE_EMAIL))) {
            $errors['recipients'] = 'Almeno un destinatario, tutti indirizzi email validi';
        }
        if ($errors) {
            throw ApiError::validation($errors);
        }

        $this->forms->saveMailSettings($site['id'], $s);
        $warnings = [];
        $siteHost = preg_replace('~^www\.~', '', parse_url($site['domain'], PHP_URL_HOST));
        if (!str_ends_with(strtolower(substr(strrchr($s['from_email'], '@'), 1)), strtolower($siteHost))) {
            $warnings[] = "Il mittente non è sul dominio del sito ($siteHost): verifica che sia autenticato su Brevo (SPF/DKIM)";
        }
        return Response::json(['data' => $this->forms->mailSettings($site['id']), 'warnings' => $warnings]);
    }

    /** Invia un'email di prova con le impostazioni salvate: mostra subito l'errore SMTP se qualcosa non va. */
    private function testMail(Request $req): Response
    {
        $site = $this->site($req->param('site'));
        $mail = $this->forms->mailSettings($site['id'], true) ?? throw new ApiError(409, 'mail_not_configured', 'SMTP non configurato');
        $to = (string) ($req->input()['to'] ?? '');
        $msg = new Message($mail['from_email'], $mail['from_name'] ?: $site['name'], "Email di prova - {$site['name']}",
            "Questa è un'email di prova inviata dal pannello per il sito {$site['domain']}.\nSe la ricevi, l'invio dei form funziona.");
        $msg->to = $to !== '' ? [$to] : $mail['recipients'];
        if (array_filter($msg->to, fn($e) => !filter_var($e, FILTER_VALIDATE_EMAIL))) {
            throw ApiError::validation(['to' => 'Email non valida']);
        }
        try {
            Smtp::fromSettings($mail)->send($msg);
        } catch (SmtpError $e) {
            throw new ApiError(422, 'smtp_failed', $e->getMessage());
        }
        return Response::json(['data' => ['sent_to' => $msg->to]]);
    }

    // ---------------------------------------------------------------- moduli

    private function updateForm(Request $req): Response
    {
        $site = $this->site($req->param('site'));
        $form = $this->forms->form($site['id'], $req->param('key'));
        $in = $req->input();
        $set = [];
        $errors = [];

        foreach (['name', 'subject', 'success_message'] as $f) {
            if (array_key_exists($f, $in)) {
                $set[$f] = trim(strip_tags((string) $in[$f]));
            }
        }
        if (isset($set['name']) && $set['name'] === '') {
            $errors['name'] = 'Non può essere vuoto';
        }
        if (array_key_exists('enabled', $in)) {
            $set['enabled'] = (bool) $in['enabled'];
        }
        if (array_key_exists('recipients', $in)) {
            $list = is_array($in['recipients']) ? array_values(array_unique(array_map('trim', $in['recipients']))) : null;
            if ($list === null || array_filter($list, fn($e) => !filter_var($e, FILTER_VALIDATE_EMAIL))) {
                $errors['recipients'] = 'Lista di email valide (vuota = destinatari predefiniti del sito)';
            } else {
                $set['recipients'] = $list;
            }
        }
        if (array_key_exists('autoreply', $in)) {
            $a = is_array($in['autoreply']) ? $in['autoreply'] : [];
            $set['autoreply'] = ['enabled' => (bool) ($a['enabled'] ?? false),
                'subject' => trim(strip_tags((string) ($a['subject'] ?? ''))), 'body' => trim(strip_tags((string) ($a['body'] ?? '')))];
            if ($set['autoreply']['enabled'] && $set['autoreply']['body'] === '') {
                $errors['autoreply.body'] = 'Testo obbligatorio se la risposta automatica è attiva';
            }
        }
        if ($errors) {
            throw ApiError::validation($errors);
        }
        if ($set) {
            $this->forms->updateForm($site['id'], $form['key'], $set);
        }
        return Response::json(['data' => $this->forms->form($site['id'], $form['key'])]);
    }

    // ---------------------------------------------------------------- messaggi

    private function submissions(Request $req): Response
    {
        $site = $this->site($req->param('site'));
        $page = $req->int('page', 1);
        $perPage = $req->int('per_page', 20, 1, 100);
        [$rows, $total] = $this->forms->submissions($site['id'], array_intersect_key($req->query, array_flip(['form', 'status'])), $page, $perPage);
        return Response::json(['data' => $rows,
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => (int) ceil($total / $perPage)]]);
    }

    private function resend(Request $req): Response
    {
        $site = $this->site($req->param('site'));
        $sub = $this->forms->submission($site['id'], (int) $req->param('id'));
        $this->forms->deliver($sub['id']);
        return Response::json(['data' => $this->forms->submission($site['id'], $sub['id'])]);
    }

    private function deleteSubmission(Request $req): Response
    {
        $site = $this->site($req->param('site'));
        $sub = $this->forms->submission($site['id'], (int) $req->param('id'));
        $this->forms->deleteSubmission($site['id'], $sub['id']);
        return Response::json(['data' => ['deleted' => $sub['id']]]);
    }
}
