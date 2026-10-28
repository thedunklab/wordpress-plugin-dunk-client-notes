<?php

declare(strict_types=1);

namespace Dunk\ClientNotes;

/**
 * Bacheca: elenco delle note con filtri, scheda della nota (screenshot, risposte, stato) e impostazioni.
 */
class Admin
{
    private const STATUS_LABEL = ['open' => 'Aperta', 'progress' => 'In lavorazione', 'resolved' => 'Risolta'];

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'settings']);
        add_action('admin_post_dcn_reply', [self::class, 'handle_reply']);
        add_action('admin_post_dcn_status', [self::class, 'handle_status']);
        add_action('admin_post_dcn_testmail', [self::class, 'handle_testmail']);
        add_action('admin_post_dcn_contact_add', [self::class, 'handle_contact_add']);
        add_action('admin_post_dcn_contact_remove', [self::class, 'handle_contact_remove']);
        add_action('admin_post_dcn_checkupdate', [self::class, 'handle_checkupdate']);
        add_action('admin_post_dcn_delete', [self::class, 'handle_delete']);
        add_action('admin_notices', [self::class, 'notice']);
        add_action('admin_head', [self::class, 'font']);
    }

    /** DM Sans nelle schermate del plugin (elenco, scheda, impostazioni). */
    public static function font(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || strpos((string) $screen->id, 'dcn-') === false) {
            return;
        }
        echo '<style>' . Plugin::font_face() . ".dcn-wrap, .dcn-wrap input, .dcn-wrap select, .dcn-wrap textarea, .dcn-wrap button, .dcn-wrap .button { font-family: 'DM Sans', -apple-system, 'Segoe UI', sans-serif; } .dcn-wrap .wp-list-table th, .dcn-wrap .wp-list-table td { vertical-align: middle; }</style>\n";
    }

    /** Firma in fondo alle schermate del plugin. */
    private static function signature(): string
    {
        return '<p style="display:flex;align-items:center;gap:8px;margin-top:28px;color:#6b6b66;font-size:12px">DUNK Client Notes <a href="https://thedunk.it" target="_blank" rel="noopener" style="display:inline-flex;background:#fee000;padding:5px 10px;border-radius:5px"><img src="' . esc_url(DCN_URL . 'assets/dunk-logo.svg') . '" alt="DUNK" style="display:block;height:14px;width:auto"></a></p>';
    }

    public static function menu(): void
    {
        add_menu_page('Note del cliente', 'Note cliente', 'edit_posts', 'dcn-notes', [self::class, 'page'], 'dashicons-format-chat', 26);
        add_submenu_page('dcn-notes', 'Note del cliente', 'Tutte le note', 'edit_posts', 'dcn-notes', [self::class, 'page']);
        add_submenu_page('dcn-notes', 'Persone esterne', 'Persone esterne', 'edit_posts', 'dcn-people', [self::class, 'people_page']);
        add_submenu_page('dcn-notes', 'Novità e aggiornamenti', 'Novità', 'manage_options', 'dcn-updates', [self::class, 'updates_page']);
        add_submenu_page('dcn-notes', 'Impostazioni note', 'Impostazioni', 'manage_options', 'dcn-settings', [self::class, 'settings_page']);
    }

    public static function handle_testmail(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Permesso negato.');
        }
        check_admin_referer('dcn_testmail');
        $err = Mail::test(wp_get_current_user()->user_email);
        set_transient('dcn_testmail_' . get_current_user_id(), $err === '' ? 'ok' : $err, 60);
        wp_safe_redirect(admin_url('admin.php?page=dcn-settings'));
        exit;
    }

    public static function notice(): void
    {
        $key = 'dcn_testmail_' . get_current_user_id();
        if ($res = get_transient($key)) {
            delete_transient($key);
            echo $res === 'ok'
                ? '<div class="notice notice-success"><p>Email di prova inviata: controlla la posta (anche lo spam).</p></div>'
                : '<div class="notice notice-error"><p><strong>Invio non riuscito:</strong> ' . esc_html((string) $res) . '</p></div>';
        }
        if (Plugin::enabled() && current_user_can('manage_options') && (function_exists('wp_get_environment_type') && wp_get_environment_type() === 'production') && !defined('DUNK_NOTES_ENABLED')) {
            echo '<div class="notice notice-warning"><p><strong>Note cliente attive.</strong> Ricordati di disattivarle prima di mettere il sito online (Note cliente → Impostazioni).</p></div>';
        }
    }

    public static function settings(): void
    {
        register_setting('dcn', 'dcn_enabled', ['type' => 'boolean', 'sanitize_callback' => static fn ($v) => $v ? 1 : 0]);
        register_setting('dcn', 'dcn_mail', ['type' => 'boolean', 'sanitize_callback' => static fn ($v) => $v ? 1 : 0]);
        register_setting('dcn', 'dcn_emails', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting('dcn', 'dcn_notify_users', ['type' => 'array', 'sanitize_callback' => static fn ($v) => array_values(array_unique(array_filter(array_map('absint', (array) $v))))]);
        register_setting('dcn', 'dcn_smtp_host', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting('dcn', 'dcn_smtp_port', ['type' => 'integer', 'sanitize_callback' => static fn ($v) => (int) $v ?: 587]);
        register_setting('dcn', 'dcn_smtp_user', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        // password: se il campo resta vuoto si tiene quella già salvata
        register_setting('dcn', 'dcn_smtp_pass', ['type' => 'string', 'sanitize_callback' => static fn ($v) => trim((string) $v) !== '' ? trim((string) $v) : (string) get_option('dcn_smtp_pass', '')]);
        register_setting('dcn', 'dcn_smtp_from', ['type' => 'string', 'sanitize_callback' => 'sanitize_email']);
        register_setting('dcn', 'dcn_smtp_name', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting('dcn', 'dcn_autoclose', ['type' => 'boolean', 'sanitize_callback' => static fn ($v) => $v ? 1 : 0]);
        register_setting('dcn', 'dcn_github_token', ['type' => 'string', 'sanitize_callback' => static fn ($v) => trim((string) $v) !== '' ? trim((string) $v) : (string) get_option('dcn_github_token', '')]);
        register_setting('dcn', 'dcn_label', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
    }

    /** Rubrica delle persone esterne al team (le stesse del pannello sul sito): elenco, aggiunta e rimozione. */
    public static function people_page(): void
    {
        if (!current_user_can('edit_posts')) {
            return;
        }
        $people = Plugin::contacts();
        $admin  = current_user_can('manage_options');
        $msg    = (string) ($_GET['msg'] ?? '');
        ?>
        <div class="wrap dcn-wrap">
            <h1>Persone esterne</h1>
            <?php if ($msg === 'added') : ?><div class="notice notice-success is-dismissible"><p>Persona aggiunta.</p></div><?php endif; ?>
            <?php if ($msg === 'removed') : ?><div class="notice notice-success is-dismissible"><p>Persona rimossa.</p></div><?php endif; ?>
            <?php if ($msg === 'invalid') : ?><div class="notice notice-error is-dismissible"><p>Inserisci nome e cognome e un indirizzo email valido.</p></div><?php endif; ?>
            <p class="description" style="max-width:640px">Chi è fuori dal team e puoi coinvolgere sulle note: scrivendo una nota o una risposta (anche dal pannello sul sito) si possono segnalare a queste persone, che ricevono una email con il testo e il link. Non sono utenti di WordPress e non hanno accesso alla bacheca.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:16px 0">
                <?php wp_nonce_field('dcn_contact_add'); ?>
                <input type="hidden" name="action" value="dcn_contact_add">
                <input type="text" name="name" class="regular-text" placeholder="Nome e cognome" maxlength="80" required>
                <input type="email" name="email" class="regular-text" placeholder="Email" maxlength="120" required>
                <button class="button button-primary">Aggiungi</button>
            </form>
            <table class="wp-list-table widefat striped" style="max-width:760px">
                <thead><tr><th>Nome e cognome</th><th>Email</th><?php if ($admin) : ?><th style="width:110px"></th><?php endif; ?></tr></thead>
                <tbody>
                <?php if (!$people) : ?><tr><td colspan="<?php echo $admin ? 3 : 2; ?>">Nessuna persona aggiunta.</td></tr><?php endif; ?>
                <?php foreach ($people as $c) : ?>
                    <tr>
                        <td><strong><?php echo esc_html($c['name']); ?></strong></td>
                        <td><a href="mailto:<?php echo esc_attr($c['email']); ?>"><?php echo esc_html($c['email']); ?></a></td>
                        <?php if ($admin) : ?><td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Rimuovere questa persona dalla rubrica?')">
                                <?php wp_nonce_field('dcn_contact_remove_' . $c['id']); ?>
                                <input type="hidden" name="action" value="dcn_contact_remove"><input type="hidden" name="cid" value="<?php echo esc_attr($c['id']); ?>">
                                <button class="button button-link-delete">Rimuovi</button>
                            </form>
                        </td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (!$admin) : ?><p class="description">Solo un amministratore può rimuovere le persone dalla rubrica.</p><?php endif; ?>
            <?php echo self::signature(); // statica ?>
        </div>
        <?php
    }

    public static function handle_contact_add(): void
    {
        if (!current_user_can('edit_posts')) {
            wp_die('Permesso negato.');
        }
        check_admin_referer('dcn_contact_add');
        $name  = mb_substr(trim(sanitize_text_field(wp_unslash((string) ($_POST['name'] ?? '')))), 0, 80);
        $email = sanitize_email(wp_unslash((string) ($_POST['email'] ?? '')));
        $msg   = 'invalid';
        if ($name !== '' && is_email($email)) {
            $id   = substr(md5(strtolower($email)), 0, 10);
            $list = array_values(array_filter(Plugin::contacts(), static fn ($c) => $c['id'] !== $id));
            if (count($list) < 50) {
                $list[] = ['id' => $id, 'name' => $name, 'email' => strtolower($email)];
                update_option('dcn_contacts', $list, false);
                $msg = 'added';
            }
        }
        wp_safe_redirect(admin_url('admin.php?page=dcn-people&msg=' . $msg));
        exit;
    }

    public static function handle_contact_remove(): void
    {
        $id = sanitize_key((string) ($_POST['cid'] ?? ''));
        if (!current_user_can('manage_options')) {
            wp_die('Permesso negato.');
        }
        check_admin_referer('dcn_contact_remove_' . $id);
        update_option('dcn_contacts', array_values(array_filter(Plugin::contacts(), static fn ($c) => $c['id'] !== $id)), false);
        wp_safe_redirect(admin_url('admin.php?page=dcn-people&msg=removed'));
        exit;
    }

    public static function settings_page(): void
    {
        $locked = defined('DUNK_NOTES_ENABLED');
        ?>
        <div class="wrap dcn-wrap">
            <h1>Note cliente · Impostazioni</h1>
            <form method="post" action="options.php">
                <?php settings_fields('dcn'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Stato</th>
                        <td>
                            <label><input type="checkbox" name="dcn_enabled" value="1" <?php checked(Plugin::enabled()); disabled($locked); ?>> Attiva le note del cliente su questo sito</label>
                            <p class="description"><?php echo $locked ? 'Impostato da <code>DUNK_NOTES_ENABLED</code> in wp-config.php.' : 'Da accendere solo sullo <strong>staging</strong>. Quando attive, sul sito compare il pulsante «Note» e le pagine diventano noindex. Per bloccare l\'accensione dalla bacheca usa <code>define(\'DUNK_NOTES_ENABLED\', false)</code> in wp-config.php.'; ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="dcn_label">Nome del progetto</label></th>
                        <td><input type="text" id="dcn_label" name="dcn_label" class="regular-text" value="<?php echo esc_attr((string) get_option('dcn_label', '')); ?>" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Dopo l'invio</th>
                        <td>
                            <label><input type="checkbox" name="dcn_autoclose" value="1" <?php checked((bool) get_option('dcn_autoclose', 1)); ?>> Chiudi il pannello dopo aver inviato una risposta</label>
                            <p class="description">Così si può scegliere subito un'altra nota nel sito. Se disattivato, la conversazione resta aperta.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Email al team</th>
                        <td>
                            <label><input type="checkbox" name="dcn_mail" value="1" <?php checked((bool) get_option('dcn_mail', 1)); ?>> Avvisami a ogni nuova nota e risposta del cliente</label><br>
                            <input type="text" name="dcn_emails" class="regular-text" value="<?php echo esc_attr((string) get_option('dcn_emails', get_option('admin_email'))); ?>">
                            <p class="description">Uno o più indirizzi, separati da virgola.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Avviso «Note pronte»</th>
                        <td>
                            <?php
                            $chosen = array_map(static fn ($u) => $u->ID, Plugin::ready_users());
                            foreach (get_users(['capability' => 'edit_posts', 'orderby' => 'display_name', 'fields' => ['ID', 'display_name', 'user_email']]) as $u) : ?>
                                <label style="display:block;margin:0 0 4px"><input type="checkbox" name="dcn_notify_users[]" value="<?php echo (int) $u->ID; ?>" <?php checked(in_array((int) $u->ID, $chosen, true)); ?>> <?php echo esc_html($u->display_name); ?> <span class="description">· <?php echo esc_html($u->user_email); ?></span></label>
                            <?php endforeach; ?>
                            <p class="description">Quando il cliente preme «Segnala note pronte» nel pannello, le persone selezionate ricevono <strong>una sola email per pagina</strong> con l'elenco delle note aperte (non una per nota). Se non ne scegli nessuna, il pulsante resta nascosto.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Invio email (SMTP)</th>
                        <td>
                            <p class="description" style="margin-top:0">Senza SMTP le email partono dal server dello staging, non autenticate, e spesso non arrivano. Con MailerSend: host <code>smtp.mailersend.net</code>, porta 587, utente e password dalle credenziali SMTP del dominio, mittente di un dominio verificato.</p>
                            <p><input type="text" name="dcn_smtp_host" class="regular-text" placeholder="smtp.mailersend.net" value="<?php echo esc_attr((string) get_option('dcn_smtp_host', '')); ?>"> <input type="number" name="dcn_smtp_port" class="small-text" placeholder="587" value="<?php echo esc_attr((string) get_option('dcn_smtp_port', 587)); ?>"></p>
                            <p><input type="text" name="dcn_smtp_user" class="regular-text" placeholder="Utente SMTP" autocomplete="off" value="<?php echo esc_attr((string) get_option('dcn_smtp_user', '')); ?>"></p>
                            <p><input type="password" name="dcn_smtp_pass" class="regular-text" placeholder="<?php echo get_option('dcn_smtp_pass') ? '•••••••• (salvata: lascia vuoto per non cambiarla)' : 'Password SMTP'; ?>" autocomplete="new-password"></p>
                            <p><input type="email" name="dcn_smtp_from" class="regular-text" placeholder="Email mittente (es. note@dunklab.it)" value="<?php echo esc_attr((string) get_option('dcn_smtp_from', '')); ?>"> <input type="text" name="dcn_smtp_name" class="regular-text" placeholder="Nome mittente" value="<?php echo esc_attr((string) get_option('dcn_smtp_name', '')); ?>"></p>
                            <p class="description"><?php echo Mail::ready() ? '<strong>SMTP attivo.</strong>' : 'SMTP non attivo (servono host, utente e password).'; ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Aggiornamenti</th>
                        <td>
                            <?php if (defined('DUNK_NOTES_GITHUB_TOKEN')) : ?>
                                <p class="description" style="margin-top:0">Token impostato da <code>DUNK_NOTES_GITHUB_TOKEN</code> in wp-config.php.</p>
                            <?php else : ?>
                                <p><input type="password" name="dcn_github_token" class="regular-text" placeholder="<?php echo get_option('dcn_github_token') ? '•••••••• (salvato: lascia vuoto per non cambiarlo)' : 'Token GitHub (sola lettura)'; ?>" autocomplete="new-password"></p>
                                <p class="description">Serve per aggiornare il plugin da questa bacheca (Note cliente → Novità). È un token GitHub con accesso in <strong>sola lettura</strong> alla repo del plugin, uguale per tutti i siti.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <?php if (Mail::ready()) : $me = wp_get_current_user(); ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('dcn_testmail'); ?>
                    <input type="hidden" name="action" value="dcn_testmail">
                    <button class="button">Invia email di prova a <?php echo esc_html($me->user_email); ?></button>
                </form>
            <?php endif; ?>
            <?php echo self::signature(); // statica ?>
        </div>
        <?php
    }

    public static function handle_checkupdate(): void
    {
        check_admin_referer('dcn_checkupdate');
        if (!current_user_can('manage_options')) {
            wp_die('Non autorizzato.', 403);
        }
        Updater::refresh();
        wp_safe_redirect(admin_url('admin.php?page=dcn-updates'));
        exit;
    }

    public static function updates_page(): void
    {
        $info = Updater::latest();
        $newer = Updater::newer($info);
        $base = plugin_basename(DCN_FILE);
        $md = $info['changelog'] !== '' ? $info['changelog'] : (string) @file_get_contents(DCN_DIR . 'CHANGELOG.md');
        ?>
        <div class="wrap dcn-wrap">
            <h1>Note cliente · Novità</h1>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Versione installata</th>
                    <td><strong><?php echo esc_html(DCN_VERSION); ?></strong></td>
                </tr>
                <tr>
                    <th scope="row">Ultima versione</th>
                    <td>
                        <?php if ($info['error'] !== '') : ?>
                            <span style="color:#b32d2e"><?php echo esc_html($info['error']); ?></span>
                        <?php elseif ($newer) : ?>
                            <strong><?php echo esc_html($info['version']); ?></strong> · disponibile
                            <?php if (!Updater::dev() && current_user_can('update_plugins')) : ?>
                                — <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(self_admin_url('update.php?action=upgrade-plugin&plugin=' . rawurlencode($base)), 'upgrade-plugin_' . $base)); ?>">Aggiorna ora</a>
                            <?php endif; ?>
                        <?php else : ?>
                            <?php echo esc_html($info['version']); ?> · il plugin è aggiornato
                        <?php endif; ?>
                        <?php if ($info['checked']) : ?><p class="description">Ultimo controllo: <?php echo esc_html(wp_date('j M Y, H:i', $info['checked'])); ?>. WordPress ricontrolla da solo ogni 12 ore.</p><?php endif; ?>
                        <?php if (Updater::dev()) : ?><p class="description">Qui il plugin è un checkout git (sviluppo): si aggiorna con git, non da bacheca.</p><?php endif; ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px">
                            <?php wp_nonce_field('dcn_checkupdate'); ?>
                            <input type="hidden" name="action" value="dcn_checkupdate">
                            <button class="button">Controlla ora</button>
                        </form>
                        <p class="description">Per averlo in automatico: Plugin → «Attiva aggiornamenti automatici» accanto a DUNK Client Notes.</p>
                    </td>
                </tr>
            </table>
            <h2>Registro delle versioni</h2>
            <div class="dcn-changelog" style="max-width:760px;background:#fff;border:1px solid #dcdcde;padding:4px 20px 12px">
                <?php echo wp_kses_post(Updater::markdown($md)); ?>
            </div>
            <?php echo self::signature(); // statica ?>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ */

    public static function page(): void
    {
        if (!current_user_can('edit_posts')) {
            return;
        }
        $id = isset($_GET['note']) ? (int) $_GET['note'] : 0;
        $id ? self::detail($id) : self::listing();
    }

    private static function listing(): void
    {
        $status = isset($_GET['status']) ? sanitize_key((string) $_GET['status']) : '';
        $path   = isset($_GET['path']) ? sanitize_text_field(wp_unslash((string) $_GET['path'])) : '';
        $paged  = max(1, (int) ($_GET['paged'] ?? 1));
        $meta   = [];
        if (isset(self::STATUS_LABEL[$status])) {
            $meta[] = ['key' => '_dcn_status', 'value' => $status];
        }
        if ($path !== '') {
            $meta[] = ['key' => '_dcn_path', 'value' => Plugin::normalize_path($path)];
        }
        $q = new \WP_Query(['post_type' => Plugin::NOTE, 'post_status' => 'publish', 'posts_per_page' => 30, 'paged' => $paged, 'orderby' => 'date', 'order' => 'DESC', 'meta_query' => $meta]);

        // pagine che hanno note (per il filtro)
        global $wpdb;
        $paths = $wpdb->get_col("SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_dcn_path' ORDER BY meta_value");
        $base  = admin_url('admin.php?page=dcn-notes');
        ?>
        <div class="wrap dcn-wrap">
            <h1 class="wp-heading-inline">Note del cliente</h1>
            <?php if (!Plugin::enabled()) : ?><p><strong>Le note sono spente.</strong> <a href="<?php echo esc_url(admin_url('admin.php?page=dcn-settings')); ?>">Attivale nelle impostazioni</a> (solo staging).</p><?php endif; ?>
            <ul class="subsubsub">
                <li><a href="<?php echo esc_url($base); ?>" <?php echo $status === '' ? 'class="current"' : ''; ?>>Tutte <span class="count">(<?php echo (int) Plugin::count(); ?>)</span></a> |</li>
                <?php $i = 0; foreach (self::STATUS_LABEL as $k => $label) : ?>
                    <li><a href="<?php echo esc_url(add_query_arg('status', $k, $base)); ?>" <?php echo $status === $k ? 'class="current"' : ''; ?>><?php echo esc_html($label); ?> <span class="count">(<?php echo (int) Plugin::count($k); ?>)</span></a><?php echo ++$i < 3 ? ' |' : ''; ?></li>
                <?php endforeach; ?>
            </ul>
            <form method="get" style="margin:1em 0">
                <input type="hidden" name="page" value="dcn-notes">
                <?php if ($status) : ?><input type="hidden" name="status" value="<?php echo esc_attr($status); ?>"><?php endif; ?>
                <select name="path" onchange="this.form.submit()">
                    <option value="">Tutte le pagine</option>
                    <?php foreach ($paths as $p) : ?><option value="<?php echo esc_attr($p); ?>" <?php selected($path, $p); ?>><?php echo esc_html($p); ?></option><?php endforeach; ?>
                </select>
            </form>
            <table class="wp-list-table widefat striped">
                <thead><tr><th style="width:3.5em">#</th><th style="width:90px">Screenshot</th><th>Nota</th><th>Pagina</th><th>Autore</th><th>Stato</th><th>Data</th></tr></thead>
                <tbody>
                <?php if (!$q->have_posts()) : ?>
                    <tr><td colspan="7">Nessuna nota.</td></tr>
                <?php endif; ?>
                <?php foreach ($q->posts as $p) : $n = Plugin::format($p); ?>
                    <tr>
                        <td><strong>#<?php echo (int) $n['n']; ?></strong></td>
                        <td><?php if ($n['shot']) : ?><a href="<?php echo esc_url(admin_url('admin.php?page=dcn-notes&note=' . $n['id'])); ?>"><img src="<?php echo esc_url($n['shot']); ?>" alt="" style="width:80px;height:auto;border-radius:6px;border:1px solid #ddd"></a><?php endif; ?></td>
                        <td><a href="<?php echo esc_url(admin_url('admin.php?page=dcn-notes&note=' . $n['id'])); ?>"><strong><?php echo esc_html(wp_trim_words($n['text'], 18)); ?></strong></a>
                            <?php if ($n['replies']) : ?><br><small><?php echo count($n['replies']); ?> rispost<?php echo count($n['replies']) === 1 ? 'a' : 'e'; ?></small><?php endif; ?></td>
                        <td><code><?php echo esc_html($n['path']); ?></code></td>
                        <td><?php echo esc_html($n['author']); ?><?php echo $n['source'] === 'team' ? ' <small>(team)</small>' : ''; ?></td>
                        <td><?php echo self::badge($n['status']); // già escapato ?></td>
                        <td><?php echo esc_html($n['date']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php
            echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post((string) paginate_links(['base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $paged, 'total' => max(1, (int) $q->max_num_pages)])) . '</div></div>';
            echo self::signature(); // statica
            ?>
        </div>
        <?php
    }

    private static function badge(string $status): string
    {
        // giallo e nero DUNK: distinti dai colori del sito
        [$bg, $fg] = ['open' => ['#fee000', '#0a0a0a'], 'progress' => ['#0a0a0a', '#fee000'], 'resolved' => ['#4ade80', '#0a0a0a']][$status] ?? ['#d9d9d4', '#0a0a0a'];

        return '<span style="display:inline-flex;align-items:center;padding:4px 11px 3px;border:1px solid #0a0a0a;border-radius:99px;background:' . esc_attr($bg) . ';color:' . esc_attr($fg) . ';font-size:12px;font-weight:600;line-height:1">' . esc_html(self::STATUS_LABEL[$status] ?? $status) . '</span>';
    }

    private static function detail(int $id): void
    {
        $post = get_post($id);
        if (!$post || $post->post_type !== Plugin::NOTE) {
            echo '<div class="wrap dcn-wrap"><h1>Nota non trovata</h1></div>';

            return;
        }
        $n    = Plugin::format($post);
        $view = add_query_arg('dcn', 'n', home_url($n['path'])) . '#dcn-' . $n['id'];
        ?>
        <div class="wrap dcn-wrap">
            <h1 class="wp-heading-inline">Nota #<?php echo (int) $n['n']; ?></h1>
            <a href="<?php echo esc_url(admin_url('admin.php?page=dcn-notes')); ?>" class="page-title-action">← Tutte le note</a>
            <?php if (isset($_GET['done'])) : ?><div class="notice notice-success is-dismissible"><p>Fatto.</p></div><?php endif; ?>
            <div style="display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:24px;margin-top:16px;max-width:1200px">
                <div>
                    <div class="postbox" style="padding:16px 20px">
                        <p style="margin-top:0"><?php echo self::badge($n['status']); ?> &nbsp; <strong><?php echo esc_html($n['author']); ?></strong><?php echo $n['source'] === 'team' ? ' (team)' : ''; ?> · <?php echo esc_html($n['date']); ?></p>
                        <p style="font-size:15px;white-space:pre-wrap"><?php echo esc_html($n['text']); ?></p>
                        <p><a class="button button-primary" href="<?php echo esc_url($view); ?>" target="_blank" rel="noopener">Apri la pagina nel punto della nota</a> <code><?php echo esc_html($n['path']); ?></code></p>
                        <?php if ($n['shot']) : ?><p><a href="<?php echo esc_url($n['shot']); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url($n['shot']); ?>" alt="Screenshot" style="max-width:100%;height:auto;border:1px solid #ddd;border-radius:8px"></a></p><?php endif; ?>
                    </div>
                    <h2>Conversazione</h2>
                    <?php foreach ($n['replies'] as $r) : ?>
                        <div class="postbox" style="padding:10px 16px;<?php echo $r['source'] === 'team' ? 'border-left:4px solid #fee000' : ''; ?>">
                            <strong><?php echo esc_html($r['author']); ?></strong><?php echo $r['source'] === 'team' ? ' (team)' : ''; ?> · <small><?php echo esc_html($r['date']); ?></small>
                            <p style="white-space:pre-wrap;margin-bottom:0"><?php echo esc_html($r['text']); ?></p>
                        </div>
                    <?php endforeach; ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('dcn_reply_' . $n['id']); ?>
                        <input type="hidden" name="action" value="dcn_reply"><input type="hidden" name="note" value="<?php echo (int) $n['id']; ?>">
                        <textarea name="text" rows="4" class="large-text" placeholder="Rispondi al cliente… (facoltativo se la nota è «Risolta» o «In lavorazione»: invia solo lo stato)"></textarea>
                        <p><button class="button button-primary">Invia risposta</button></p>
                    </form>
                </div>
                <div>
                    <div class="postbox" style="padding:12px 16px">
                        <h3 style="margin-top:0">Stato</h3>
                        <?php foreach (self::STATUS_LABEL as $k => $label) : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                                <?php wp_nonce_field('dcn_status_' . $n['id']); ?>
                                <input type="hidden" name="action" value="dcn_status"><input type="hidden" name="note" value="<?php echo (int) $n['id']; ?>"><input type="hidden" name="status" value="<?php echo esc_attr($k); ?>">
                                <button class="button <?php echo $n['status'] === $k ? 'button-primary' : ''; ?>"><?php echo esc_html($label); ?></button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                    <div class="postbox" style="padding:12px 16px">
                        <h3 style="margin-top:0">Dettagli</h3>
                        <p><strong>Pagina:</strong> <code><?php echo esc_html($n['path']); ?></code><br>
                           <strong>Elemento:</strong> <code style="word-break:break-all"><?php echo esc_html($n['selector'] ?: '—'); ?></code><br>
                           <strong>Larghezza schermo:</strong> <?php echo (int) $n['vw']; ?>px</p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Eliminare la nota e le sue risposte?')">
                            <?php wp_nonce_field('dcn_delete_' . $n['id']); ?>
                            <input type="hidden" name="action" value="dcn_delete"><input type="hidden" name="note" value="<?php echo (int) $n['id']; ?>">
                            <button class="button button-link-delete">Elimina la nota</button>
                        </form>
                    </div>
                </div>
            </div>
            <?php echo self::signature(); // statica ?>
        </div>
        <?php
    }

    /* ---- azioni ---- */

    private static function guard(string $action): int
    {
        $id = (int) ($_POST['note'] ?? 0);
        if (!current_user_can('edit_posts')) {
            wp_die('Permesso negato.');
        }
        check_admin_referer($action . '_' . $id);
        $post = get_post($id);
        if (!$post || $post->post_type !== Plugin::NOTE) {
            wp_die('Nota non trovata.');
        }

        return $id;
    }

    public static function handle_reply(): void
    {
        $id   = self::guard('dcn_reply');
        $text = mb_substr(trim(sanitize_textarea_field(wp_unslash((string) ($_POST['text'] ?? '')))), 0, 2000);
        if ($text === '') {
            // senza testo vale come avviso del nuovo stato (es. «Risolta»), se la nota non è più aperta
            $text = Plugin::status_text((string) (get_post_meta($id, '_dcn_status', true) ?: 'open'));
        }
        if ($text !== '') {
            $rid = Plugin::insert(['post_type' => Plugin::REPLY, 'post_status' => 'publish', 'post_parent' => $id, 'post_title' => mb_substr($text, 0, 60), 'post_content' => $text]);
            update_post_meta($rid, '_dcn_author', wp_get_current_user()->display_name);
            update_post_meta($rid, '_dcn_source', 'team');
        }
        wp_safe_redirect(admin_url('admin.php?page=dcn-notes&note=' . $id . '&done=1'));
        exit;
    }

    public static function handle_status(): void
    {
        $id     = self::guard('dcn_status');
        $status = sanitize_key((string) ($_POST['status'] ?? ''));
        if (isset(self::STATUS_LABEL[$status])) {
            update_post_meta($id, '_dcn_status', $status);
        }
        wp_safe_redirect(admin_url('admin.php?page=dcn-notes&note=' . $id . '&done=1'));
        exit;
    }

    public static function handle_delete(): void
    {
        $id = self::guard('dcn_delete');
        foreach (get_posts(['post_type' => Plugin::REPLY, 'post_parent' => $id, 'posts_per_page' => -1, 'fields' => 'ids']) as $rid) {
            wp_delete_post($rid, true);
        }
        if ($shot = (string) get_post_meta($id, '_dcn_shot', true)) {
            $f = trailingslashit(wp_get_upload_dir()['basedir']) . $shot;
            if (is_file($f)) {
                wp_delete_file($f);
            }
        }
        wp_delete_post($id, true);
        wp_safe_redirect(admin_url('admin.php?page=dcn-notes'));
        exit;
    }
}
