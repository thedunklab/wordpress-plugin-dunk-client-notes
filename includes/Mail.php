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
}
