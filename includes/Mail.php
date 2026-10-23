<?php

declare(strict_types=1);

namespace Dunk\ClientNotes;

/**
 * Invio delle email via SMTP autenticato (es. MailerSend), perché la posta locale del server dello staging
 * non è autenticata (niente SPF/DKIM) e finisce in spam o viene scartata. Si attiva solo con host, utente e password.
 * Vale per tutte le email del sito (anche quelle di WordPress).
 */
class Mail
{
    /** Testo alternativo e logo incorporato per la prossima email HTML. */
    private static string $alt = '';
    private static bool $logo = false;

    public static function init(): void
    {
        add_action('phpmailer_init', [self::class, 'configure']);
        add_filter('wp_mail_from', static fn ($from) => self::ready() && is_email((string) get_option('dcn_smtp_from')) ? (string) get_option('dcn_smtp_from') : $from);
        add_filter('wp_mail_from_name', static fn ($name) => self::ready() && get_option('dcn_smtp_name') ? (string) get_option('dcn_smtp_name') : $name);
    }

    public static function ready(): bool
    {
        return get_option('dcn_smtp_host') && get_option('dcn_smtp_user') && get_option('dcn_smtp_pass');
    }

    /** @param \PHPMailer\PHPMailer\PHPMailer $mailer */
    public static function configure($mailer): void
    {
        if (self::$alt !== '') {
            $mailer->AltBody = self::$alt;
        }
        if (self::$logo) {
            $mailer->addEmbeddedImage(DCN_DIR . 'assets/dunk-logo-mail.png', 'dunklogo', 'dunk-logo.png', 'base64', 'image/png');
        }
        if (!self::ready()) {
            return;
        }
        $mailer->isSMTP();
        $mailer->Host       = (string) get_option('dcn_smtp_host');
        $mailer->Port       = (int) get_option('dcn_smtp_port', 587);
        $mailer->SMTPAuth   = true;
        $mailer->Username   = (string) get_option('dcn_smtp_user');
        $mailer->Password   = (string) get_option('dcn_smtp_pass');
        $mailer->SMTPSecure = $mailer->Port === 465 ? 'ssl' : 'tls';
        $mailer->Timeout    = 15;
    }

    /** Email di prova: restituisce l'errore (testo) oppure '' se l'invio è riuscito. */
    public static function test(string $to): string
    {
        $error = '';
        $cb    = static function (\WP_Error $e) use (&$error): void {
            $error = $e->get_error_message();
        };
        add_action('wp_mail_failed', $cb);
        $ok = wp_mail($to, '[' . Plugin::label() . '] Email di prova', "Se leggi questo messaggio, l'invio delle email dal sito funziona.\n");
        remove_action('wp_mail_failed', $cb);

        return $ok ? '' : ($error ?: 'Invio non riuscito.');
    }

    /** Invia un'email HTML nello stile DUNK (testo semplice come alternativa). */
    public static function send($to, string $subject, array $spec, string $text): bool
    {
        self::$alt  = $text;
        self::$logo = true;
        $ok         = wp_mail($to, $subject, self::render($spec, 'cid:dunklogo'), ['Content-Type: text/html; charset=UTF-8']);
        self::$alt  = '';
        self::$logo = false;

        return $ok;
    }

    /**
     * HTML dell'email (tabelle e stili inline, compatibile con i client di posta).
     * $spec: title, intro, items [['n', 'author', 'text', 'new']], buttons [['label', 'url', 'primary']], footer.
     */
    public static function render(array $spec, string $logo): string
    {
        $e     = static fn ($v): string => esc_html((string) $v);
        $font  = "'DM Sans',-apple-system,'Segoe UI',Helvetica,Arial,sans-serif";
        $items = '';
        foreach ((array) ($spec['items'] ?? []) as $it) {
            $new    = !empty($it['new']) ? '<span style="display:inline-block;margin-left:6px;padding:1px 7px;border:1px solid #fde000;border-radius:99px;background:#fde000;color:#1a1600;font-size:11px;font-weight:700;vertical-align:middle">nuova</span>' : '';
            $items .= '<tr><td style="padding:10px 0;border-top:1px solid #333333;vertical-align:top;width:40px">'
                . '<div style="width:28px;height:28px;border-radius:50%;background:#fde000;font-size:12px;font-weight:700;line-height:28px;text-align:center;color:#1a1600">' . $e($it['n']) . '</div></td>'
                . '<td style="padding:10px 0;border-top:1px solid #333333;vertical-align:top;font-size:14px;line-height:1.45;color:#d9d9d4"><strong style="color:#f3f3f0">' . $e($it['author']) . '</strong>' . $new . '<br>' . nl2br($e($it['text'])) . '</td></tr>';
        }
        $buttons = '';
        foreach ((array) ($spec['buttons'] ?? []) as $b) {
            $primary  = !empty($b['primary']);
            $buttons .= '<a href="' . esc_url($b['url']) . '" style="display:inline-block;margin:0 8px 8px 0;padding:12px 22px;border:1px solid ' . ($primary ? '#fde000' : '#333333') . ';border-radius:99px;background:' . ($primary ? '#fde000' : '#1c1c1c') . ';color:' . ($primary ? '#1a1600' : '#f3f3f0') . ';font-size:14px;font-weight:700;text-decoration:none">' . $e($b['label']) . '</a>';
        }

        return '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($spec['title'] ?? '') . '</title></head>'
            . '<body style="margin:0;padding:0;background-color:#121212;background-image:radial-gradient(circle,#262626 1px,rgba(0,0,0,0) 1.5px);background-size:22px 22px;background-position:11px 11px;font-family:' . $font . '">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#121212;background-image:radial-gradient(circle,#262626 1px,rgba(0,0,0,0) 1.5px);background-size:22px 22px;background-position:11px 11px"><tr><td align="center" style="padding:24px 12px">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;border:1px solid #333333;border-radius:16px;background:#181818;overflow:hidden">'
            . '<tr><td style="padding:24px 28px;background:#121212;border-bottom:1px solid #333333"><img src="' . esc_attr($logo) . '" width="96" alt="DUNK" style="display:block;border:0;height:auto"></td></tr>'
            . '<tr><td style="padding:28px 28px 8px;font-family:' . $font . ';color:#f3f3f0">'
            . '<p style="margin:0 0 6px;color:#a8a8a3;font-size:12px;font-weight:600;letter-spacing:.06em;text-transform:uppercase">' . $e($spec['project'] ?? '') . '</p>'
            . '<h1 style="margin:0 0 14px;font-size:24px;line-height:1.2;font-weight:700">' . $e($spec['title'] ?? '') . '</h1>'
            . '<p style="margin:0 0 20px;font-size:15px;line-height:1.5;color:#d9d9d4">' . nl2br($e($spec['intro'] ?? '')) . '</p>'
            . ($items !== '' ? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px">' . $items . '</table>' : '')
            . '<div style="margin:0 0 8px">' . $buttons . '</div>'
            . '</td></tr>'
            . '<tr><td style="padding:16px 28px;border-top:1px solid #333333;background:#121212;font-family:' . $font . ';font-size:12px;color:#a8a8a3">' . $e($spec['footer'] ?? 'DUNK Client Notes · by DUNK') . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }
}
