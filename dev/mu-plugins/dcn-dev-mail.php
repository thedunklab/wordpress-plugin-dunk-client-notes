<?php
/**
 * Solo ambiente di sviluppo: manda tutte le email a Mailpit (http://localhost:8035).
 * Le impostazioni SMTP del plugin restano vuote, così non si sovrappongono.
 */

add_action('phpmailer_init', static function ($mailer): void {
    $mailer->isSMTP();
    $mailer->Host        = 'mailpit';
    $mailer->Port        = 1025;
    $mailer->SMTPAuth    = false;
    $mailer->SMTPSecure  = '';
    $mailer->SMTPAutoTLS = false;
}, 99);

// Il mittente predefinito (wordpress@localhost) non è un indirizzo valido per PHPMailer.
add_filter('wp_mail_from', static fn ($from) => str_ends_with((string) $from, '@localhost') ? 'wordpress@dcn.test' : $from);
