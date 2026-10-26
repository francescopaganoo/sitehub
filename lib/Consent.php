<?php
declare(strict_types=1);

/**
 * Gestione cookie del sito clonato:
 *  - rimuove il vecchio sistema cookie (Complianz, Cookie Notice, CookieYes, Iubenda, Cookiebot…)
 *    ripristinando gli script/iframe che quel sistema teneva bloccati;
 *  - riconosce i servizi di terze parti e li blocca finché l'utente non dà il consenso;
 *  - genera /_ssg/consent.js (banner + config), che poi il pannello potrà riscrivere.
 *
 * Contenuti bloccati:
 *  <script type="text/plain" data-ssg-consent="statistics" data-ssg-service="google-analytics">
 *  <div class="ssg-ph" data-ssg-consent="marketing" data-ssg-service="youtube"><template>…</template></div>
 */
final class Consent
{
    public const CATEGORIES = [
        'statistics' => ['label' => 'Statistiche',
            'description' => 'Ci aiutano a capire come i visitatori usano il sito, in forma aggregata.'],
        'marketing'  => ['label' => 'Marketing e contenuti di terze parti',
            'description' => 'Usati da servizi esterni per mostrare contenuti incorporati (video, mappe, social) e per la pubblicità.'],
    ];

    /** src = script esterni, inline = script inline, iframe = src degli iframe, video = URL nei widget video Elementor */
    public const SERVICES = [
        'google-analytics' => ['name' => 'Google Analytics', 'category' => 'statistics', 'policy' => 'https://policies.google.com/privacy',
            'src' => ['~googletagmanager\.com/gtag/js\?id=G-~', '~google-analytics\.com/(analytics|ga)\.js~'],
            'inline' => ['~gtag\(\s*[\'"]config[\'"]\s*,\s*[\'"]G-~', '~GoogleAnalyticsObject~', '~\bga\(\s*[\'"]create~']],
        'google-ads' => ['name' => 'Google Ads', 'category' => 'marketing', 'policy' => 'https://policies.google.com/privacy',
            'src' => ['~googletagmanager\.com/gtag/js\?id=AW-~', '~googleadservices\.com~'],
            'inline' => ['~gtag\(\s*[\'"]config[\'"]\s*,\s*[\'"]AW-~']],
        'google-tag-manager' => ['name' => 'Google Tag Manager', 'category' => 'marketing', 'policy' => 'https://policies.google.com/privacy',
            'src' => ['~googletagmanager\.com/gtm\.js~'],
            'inline' => ['~googletagmanager\.com/gtm\.js~'],
            'iframe' => ['~googletagmanager\.com/ns\.html~']],
        'meta-pixel' => ['name' => 'Meta Pixel', 'category' => 'marketing', 'policy' => 'https://www.facebook.com/privacy/policy/',
            'src' => ['~connect\.facebook\.net/.+/fbevents\.js~'],
            'inline' => ['~fbq\(\s*[\'"]init~', '~fbevents\.js~']],
        'google-maps' => ['name' => 'Google Maps', 'category' => 'marketing', 'policy' => 'https://policies.google.com/privacy',
            'src' => ['~maps\.googleapis\.com/maps/api/js~'],
            'iframe' => ['~google\.[a-z.]+/maps~', '~maps\.google\.[a-z.]+~']],
        'youtube' => ['name' => 'YouTube', 'category' => 'marketing', 'policy' => 'https://policies.google.com/privacy',
            'src' => ['~youtube\.com/iframe_api~'],
            'iframe' => ['~youtube(-nocookie)?\.com/embed~'],
            'video' => ['~youtube(-nocookie)?\.com/|youtu\.be/~']],
        'vimeo' => ['name' => 'Vimeo', 'category' => 'statistics', 'policy' => 'https://vimeo.com/privacy',
            'src' => ['~player\.vimeo\.com/api~'],
            'iframe' => ['~player\.vimeo\.com~'],
            'video' => ['~vimeo\.com/~']],
        'facebook' => ['name' => 'Facebook', 'category' => 'marketing', 'policy' => 'https://www.facebook.com/privacy/policy/',
            'src' => ['~connect\.facebook\.net/.+/sdk\.js~'],
            'iframe' => ['~facebook\.com/plugins/~']],
        'instagram' => ['name' => 'Instagram', 'category' => 'marketing', 'policy' => 'https://privacycenter.instagram.com/policy',
            'src' => ['~instagram\.com/embed\.js~'],
            'iframe' => ['~instagram\.com/~']],
        'hotjar' => ['name' => 'Hotjar', 'category' => 'statistics', 'policy' => 'https://www.hotjar.com/legal/policies/privacy/',
            'src' => ['~static\.hotjar\.com~'], 'inline' => ['~static\.hotjar\.com~']],
        'clarity' => ['name' => 'Microsoft Clarity', 'category' => 'statistics', 'policy' => 'https://privacy.microsoft.com/privacystatement',
            'src' => ['~clarity\.ms/tag~'], 'inline' => ['~clarity\.ms/tag~']],
        'linkedin' => ['name' => 'LinkedIn Insight', 'category' => 'marketing', 'policy' => 'https://www.linkedin.com/legal/privacy-policy',
            'src' => ['~snap\.licdn\.com~'], 'inline' => ['~_linkedin_partner_id~']],
        'tiktok' => ['name' => 'TikTok Pixel', 'category' => 'marketing', 'policy' => 'https://www.tiktok.com/legal/privacy-policy-eu',
            'src' => ['~analytics\.tiktok\.com~'], 'inline' => ['~analytics\.tiktok\.com~']],
    ];

    /** Servizi da togliere del tutto: servivano solo a WordPress (es. reCAPTCHA dei form). */
    private const DROP = ['~google\.com/recaptcha~', '~gstatic\.com/recaptcha~', '~recaptcha/api\.js~'];

    /** Banner cookie esistenti da rimuovere. Il testo di #cmplz-document (cookie policy) resta. */
    private const CMP_REMOVE = [
        'script[id^="cmplz-"]', 'link[id^="cmplz-banner"]', 'link[id="cmplz-general-css"]', 'style[id^="cmplz-"]',
        '#cmplz-cookiebanner-container', '#cmplz-manage-consent', '#cmplz-manage-consent-container-nojavascript',
        '.cmplz-blocked-content-notice',
        '#cookie-notice', 'script[id^="cookie-notice"]', 'link[id^="cookie-notice"]',
        'script[id^="cookie-law-info"]', 'link[id^="cookie-law-info"]', '#cookie-law-info-bar', '.cli-modal',
        '.cky-consent-container', '.cky-overlay', '.cky-btn-revisit-wrapper', '.cky-modal',
        '#moove_gdpr_cookie_info_bar', '#moove_gdpr_cookie_modal', '#moove_gdpr_save_popup_settings_button',
        'script[id^="moove_gdpr"]', 'link[id^="moove_gdpr"]',
        'script[src*="iubenda.com"]', 'script[src*="cookiebot.com"]', 'script[id="Cookiebot"]',
    ];

    /** Link "gestisci consensi" dei vari plugin: diventano l'apertura del nostro pannello preferenze. */
    private const MANAGE_LINKS = '.cmplz-show-banner, .cmplz-manage-consent, .cn-revoke-cookie, .cky-banner-element, '
        . '.iubenda-cs-preferences-link, a[href*="Cookiebot.renew"], #manage-consent, .moove-gdpr-info-bar-hidden';

    private array $found = [];
    private array $unknown = [];
    private ?string $policyPage = null;
    private ?string $oldCmp = null;
    private ?string $cmpCssUrl = null;

    public function __construct(private string $originHost)
    {
    }

    /** Dopo la riscrittura URL: i contenuti congelati nei <template> sono già sistemati. */
    public function block(Dom\HTMLDocument $doc): void
    {
        $this->blockScripts($doc);
        $this->blockEmbeds($doc);
    }

    // ---------------------------------------------------------------- vecchi banner

    /** Prima della riscrittura URL: così le risorse del vecchio banner non vengono scaricate. */
    public function removeOldCmp(Dom\HTMLDocument $doc, string $pagePath): void
    {
        $html = $doc->saveHtml();
        foreach (['complianz' => 'cmplz', 'Cookie Notice' => 'cookie-notice', 'CookieYes' => 'cky-consent',
                     'GDPR Cookie Compliance' => 'moove_gdpr', 'Iubenda' => 'iubenda.com', 'Cookiebot' => 'cookiebot.com',
                     'Borlabs (non supportato)' => 'borlabs', 'Real Cookie Banner (non supportato)' => 'real-cookie-banner'] as $name => $needle) {
            if (stripos($html, $needle) !== false) {
                $this->oldCmp ??= $name;
            }
        }

        // ripristina ciò che il vecchio plugin teneva bloccato: lo ribloccheremo noi
        foreach ($doc->querySelectorAll('[data-cmplz-src], [data-src-cmplz]') as $el) {
            $el->setAttribute('src', $el->getAttribute('data-cmplz-src') ?? $el->getAttribute('data-src-cmplz'));
            $el->removeAttribute('data-cmplz-src');
            $el->removeAttribute('data-src-cmplz');
        }
        foreach ($doc->querySelectorAll('[data-cmplz-elementor-settings]') as $el) {
            $el->setAttribute('data-settings', $el->getAttribute('data-cmplz-elementor-settings'));
            $el->removeAttribute('data-cmplz-elementor-settings');
        }
        foreach ($doc->querySelectorAll('script[type="text/plain"]') as $s) {
            $blockedBy = $s->hasAttribute('data-category') || $s->hasAttribute('data-cookieconsent')
                || str_contains($s->getAttribute('class') ?? '', '_iub_cs_activate');
            if ($blockedBy && !$s->hasAttribute('data-ssg-consent')) {
                $s->removeAttribute('type');
            }
        }
        foreach ($doc->querySelectorAll('script:not([src])') as $s) {
            if (preg_match('~_iub\s*=|_iub\.csConfiguration|window\.cookieconsent|\.cmplz-|cmplz_~', $s->textContent)) {
                $s->remove();
            }
        }

        // il CSS del vecchio banner serve solo a leggerne il colore
        $cmpCss = $doc->querySelector('link[id^="cmplz-banner"]');
        if ($cmpCss && !$this->cmpCssUrl) {
            $this->cmpCssUrl = $cmpCss->getAttribute('href');
        }

        foreach (self::CMP_REMOVE as $sel) {
            foreach ($doc->querySelectorAll($sel) as $el) {
                $el->remove();
            }
        }

        foreach ($doc->querySelectorAll(self::MANAGE_LINKS) as $a) {
            $a->setAttribute('data-ssg-consent-open', '');
            $a->setAttribute('href', '#');
            $a->setAttribute('role', 'button');
        }

        // cookie policy di Complianz: il testo resta, i suoi controlli diventano il nostro pulsante
        $policy = $doc->querySelector('#cmplz-document.cookie-statement, .cmplz-document.cookie-statement');
        if ($policy) {
            $policy->setAttribute('data-ssg-cookie-policy', '');
            $this->policyPage ??= $pagePath;
            foreach ($doc->querySelectorAll('#cmplz-manage-consent-container') as $c) {
                $p = $doc->createElement('p');
                $btn = $doc->createElement('a');
                $btn->setAttribute('href', '#');
                $btn->setAttribute('role', 'button');
                $btn->setAttribute('data-ssg-consent-open', '');
                $btn->textContent = 'Gestisci le preferenze sui cookie';
                $p->appendChild($btn);
                $c->replaceWith($p);
            }
        }
    }

    // ---------------------------------------------------------------- blocco

    private function blockScripts(Dom\HTMLDocument $doc): void
    {
        foreach ($doc->querySelectorAll('script') as $s) {
            $type = strtolower($s->getAttribute('type') ?? '');
            if ($type !== '' && !in_array($type, ['text/javascript', 'application/javascript', 'module'], true)) {
                continue;
            }
            $src = $s->getAttribute('src');
            $haystack = $src ?? $s->textContent;

            if ($src && $this->matchesAny($src, self::DROP)) {
                $s->remove();
                continue;
            }

            $service = $this->detect($haystack, $src ? 'src' : 'inline');
            if ($service) {
                $s->setAttribute('type', 'text/plain');
                if ($type === 'module') {
                    $s->setAttribute('data-ssg-type', 'module');
                }
                $s->setAttribute('data-ssg-consent', self::SERVICES[$service]['category']);
                $s->setAttribute('data-ssg-service', $service);
            } elseif ($src) {
                $this->noteUnknown($src);
            }
        }
    }

    private function blockEmbeds(Dom\HTMLDocument $doc): void
    {
        // widget video di Elementor: il player viene creato dal JS leggendo data-settings,
        // quindi va congelato l'intero widget, non solo l'iframe
        foreach ($doc->querySelectorAll('.elementor-widget-video[data-settings]') as $w) {
            $settings = str_replace('\\/', '/', $w->getAttribute('data-settings'));
            $service = $this->detect($settings, 'video');
            if ($service) {
                $this->freeze($doc, $w, $service, null);
            }
        }

        foreach ($doc->querySelectorAll('iframe[src]') as $f) {
            $src = $f->getAttribute('src');
            if ($this->matchesAny($src, self::DROP)) {
                $f->remove();
                continue;
            }
            $service = $this->detect($src, 'iframe');
            if ($service) {
                $w = (int) $f->getAttribute('width');
                $h = (int) $f->getAttribute('height');
                $this->freeze($doc, $f, $service, $w > 0 && $h > 0 ? "$w / $h" : null);
            } else {
                $this->noteUnknown($src);
            }
        }

        // <noscript> del pixel / GTM: senza JS non si chiede il consenso, quindi via
        foreach ($doc->querySelectorAll('noscript') as $n) {
            // il parser legge il contenuto di <noscript> come HTML, non come testo
            $inner = $doc->saveHtml($n);
            if ($this->detect($inner, 'iframe') || preg_match('~facebook\.com/tr\?~', $inner)) {
                $n->remove();
            }
        }
    }

    /** Sostituisce l'elemento con un segnaposto che contiene l'originale in un <template>. */
    private function freeze(Dom\HTMLDocument $doc, Dom\Element $el, string $service, ?string $ratio): void
    {
        $ph = $doc->createElement('div');
        $ph->setAttribute('class', 'ssg-ph');
        $ph->setAttribute('data-ssg-consent', self::SERVICES[$service]['category']);
        $ph->setAttribute('data-ssg-service', $service);
        $ph->setAttribute('style', 'aspect-ratio:' . ($ratio ?? '16 / 9') . ';width:100%');

        $html = $doc->saveHtml($el);
        $el->replaceWith($ph);
        $tpl = $doc->createElement('template');
        $ph->appendChild($tpl);
        $tpl->innerHTML = $html;
    }

    private function detect(string $haystack, string $kind): ?string
    {
        foreach (self::SERVICES as $id => $svc) {
            if ($this->matchesAny($haystack, $svc[$kind] ?? [])) {
                $this->found[$id] = true;
                return $id;
            }
        }
        return null;
    }

    private function matchesAny(string $s, array $patterns): bool
    {
        foreach ($patterns as $re) {
            if (preg_match($re, $s)) {
                return true;
            }
        }
        return false;
    }

    /** Script/iframe esterni non in catalogo: da verificare a mano. */
    private function noteUnknown(string $src): void
    {
        $host = parse_url($src, PHP_URL_HOST);
        if ($host && !str_ends_with(strtolower($host), $this->originHost)) {
            $this->unknown[$host] = true;
        }
    }

    // ---------------------------------------------------------------- output

    /** Config del banner. Il pannello la riscriverà (testi, servizi aggiunti, endpoint log). */
    public function config(string $slug, string $publicDir, ?string $privacyPage): array
    {
        $services = [];
        $categories = [];
        foreach (array_keys($this->found) as $id) {
            $svc = self::SERVICES[$id];
            $services[] = ['id' => $id, 'name' => $svc['name'], 'category' => $svc['category'], 'policy' => $svc['policy']];
            $categories[$svc['category']] = ['id' => $svc['category']] + self::CATEGORIES[$svc['category']];
        }

        return [
            'site'         => $slug,
            'version'      => 1,
            'expiry_days'  => 180,
            'policy_url'   => $this->policyPage,
            'privacy_url'  => $privacyPage,
            'log_endpoint' => null,
            'accent'       => $this->accentColor($publicDir),
            'categories'   => array_values(array_intersect_key($categories, self::CATEGORIES)),
            'services'     => $services,
        ];
    }

    public function report(): array
    {
        return [
            'old_cmp'     => $this->oldCmp,
            'services'    => array_keys($this->found),
            'unknown'     => array_keys($this->unknown),
            'policy_page' => $this->policyPage,
        ];
    }

    /** Colore del banner: quello del vecchio banner Complianz, altrimenti il primario del kit Elementor. */
    private function accentColor(string $publicDir): ?string
    {
        if ($this->cmpCssUrl) {
            $css = Http::get($this->cmpCssUrl, 0)['body'];
            if (preg_match('~--cmplz_button_accept_background_color:\s*(#[0-9a-f]{3,8})~i', $css, $m)) {
                return $m[1];
            }
        }
        foreach (glob($publicDir . '/wp-content/uploads/elementor/css/post-*.css') ?: [] as $file) {
            if (preg_match('~--e-global-color-primary:\s*(#[0-9a-f]{3,8})~i', file_get_contents($file), $m)) {
                return $m[1];
            }
        }
        return null;
    }
}
