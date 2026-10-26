<?php
declare(strict_types=1);

namespace Panel\Api;

use Panel\Auth;
use Panel\Http\ApiError;
use Panel\Http\Request;
use Panel\Http\Response;
use Panel\Http\Router;
use Panel\Mail\Message;
use Panel\Mail\Smtp;
use Panel\Mail\SmtpAccounts;
use Panel\Mail\SmtpError;
use Panel\Publish\Publisher;
use Panel\Store;

/** Account SMTP riutilizzabili tra i siti. Solo token agenzia: sono credenziali dell'agenzia. */
final class SmtpAccountsApi extends Api
{
    public function __construct(Store $store, Publisher $publisher, array $principal, private SmtpAccounts $accounts)
    {
        parent::__construct($store, $publisher, $principal);
    }

    public function routes(Router $r): void
    {
        $r->add('GET', '/smtp-accounts', fn(Request $q) => $this->agency(fn() => Response::json(['data' => $this->accounts->all()])));
        $r->add('POST', '/smtp-accounts', fn(Request $q) => $this->agency(fn() => Response::json(['data' => $this->accounts->save(null, $q->input())], 201)));
        $r->add('GET', '/smtp-accounts/{id}', fn(Request $q) => $this->agency(fn() => Response::json(['data' => $this->accounts->get($this->id($q))])));
        $r->add('PATCH', '/smtp-accounts/{id}', fn(Request $q) => $this->agency(fn() => Response::json(['data' => $this->accounts->save($this->id($q), $q->input())])));
        $r->add('DELETE', '/smtp-accounts/{id}', fn(Request $q) => $this->agency(function () use ($q) {
            $this->accounts->delete($this->id($q));
            return Response::json(['data' => ['deleted' => $this->id($q)]]);
        }));
        $r->add('POST', '/smtp-accounts/{id}/test', fn(Request $q) => $this->agency(fn() => $this->test($q)));
    }

    /** Email di prova con le credenziali dell'account: serve un mittente, l'account non ne ha uno. */
    private function test(Request $req): Response
    {
        $account = $this->accounts->get($this->id($req), true);
        $in = $req->input();
        $to = trim((string) ($in['to'] ?? ''));
        $from = trim((string) ($in['from_email'] ?? ''));
        $errors = [];
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $errors['to'] = 'Destinatario non valido';
        }
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $errors['from_email'] = 'Mittente non valido (un indirizzo di un dominio autenticato su Brevo)';
        }
        if ($errors) {
            throw ApiError::validation($errors);
        }
        $msg = new Message($from, trim((string) ($in['from_name'] ?? '')) ?: $account['name'], "Email di prova - {$account['name']}",
            "Questa è un'email di prova inviata da SiteHub con l'account SMTP «{$account['name']}».\nSe la ricevi, le credenziali funzionano.");
        $msg->to = [$to];
        try {
            Smtp::fromSettings($account)->send($msg);
        } catch (SmtpError $e) {
            throw new ApiError(422, 'smtp_failed', $e->getMessage());
        }
        return Response::json(['data' => ['sent_to' => [$to]]]);
    }

    private function agency(callable $fn): Response
    {
        Auth::requireAgency($this->principal);
        return $fn();
    }

    private function id(Request $req): int
    {
        $id = filter_var($req->param('id'), FILTER_VALIDATE_INT);
        if ($id === false) {
            throw ApiError::notFound('Account SMTP');
        }
        return $id;
    }
}
