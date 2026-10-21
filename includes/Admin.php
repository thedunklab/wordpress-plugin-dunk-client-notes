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
        add_action('admin_post_dcn_delete', [self::class, 'handle_delete']);
        add_action('admin_notices', [self::class, 'notice']);
    }

    public static function menu(): void
    {
        add_menu_page('Note del cliente', 'Note cliente', 'edit_posts', 'dcn-notes', [self::class, 'page'], 'dashicons-format-chat', 26);
        add_submenu_page('dcn-notes', 'Note del cliente', 'Tutte le note', 'edit_posts', 'dcn-notes', [self::class, 'page']);
        add_submenu_page('dcn-notes', 'Impostazioni note', 'Impostazioni', 'manage_options', 'dcn-settings', [self::class, 'settings_page']);
    }

    public static function notice(): void
    {
        if (Plugin::enabled() && current_user_can('manage_options') && (function_exists('wp_get_environment_type') && wp_get_environment_type() === 'production') && !defined('DUNK_NOTES_ENABLED')) {
            echo '<div class="notice notice-warning"><p><strong>Note cliente attive.</strong> Ricordati di disattivarle prima di mettere il sito online (Note cliente → Impostazioni).</p></div>';
        }
    }

    public static function settings(): void
    {
        register_setting('dcn', 'dcn_enabled', ['type' => 'boolean', 'sanitize_callback' => static fn ($v) => $v ? 1 : 0]);
        register_setting('dcn', 'dcn_mail', ['type' => 'boolean', 'sanitize_callback' => static fn ($v) => $v ? 1 : 0]);
        register_setting('dcn', 'dcn_emails', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting('dcn', 'dcn_label', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
    }

    public static function settings_page(): void
    {
        $locked = defined('DUNK_NOTES_ENABLED');
        ?>
        <div class="wrap">
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
                        <th scope="row">Email al team</th>
                        <td>
                            <label><input type="checkbox" name="dcn_mail" value="1" <?php checked((bool) get_option('dcn_mail', 1)); ?>> Avvisami a ogni nuova nota e risposta del cliente</label><br>
                            <input type="text" name="dcn_emails" class="regular-text" value="<?php echo esc_attr((string) get_option('dcn_emails', get_option('admin_email'))); ?>">
                            <p class="description">Uno o più indirizzi, separati da virgola.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
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
        <div class="wrap">
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
            ?>
        </div>
        <?php
    }

    private static function badge(string $status): string
    {
        // giallo e nero DUNK: distinti dai colori del sito
        [$bg, $fg] = ['open' => ['#fee000', '#0a0a0a'], 'progress' => ['#0a0a0a', '#fee000'], 'resolved' => ['#d9d9d4', '#0a0a0a']][$status] ?? ['#d9d9d4', '#0a0a0a'];

        return '<span style="display:inline-block;padding:2px 10px;border:1px solid #0a0a0a;border-radius:99px;background:' . esc_attr($bg) . ';color:' . esc_attr($fg) . ';font-size:12px;font-weight:600">' . esc_html(self::STATUS_LABEL[$status] ?? $status) . '</span>';
    }

    private static function detail(int $id): void
    {
        $post = get_post($id);
        if (!$post || $post->post_type !== Plugin::NOTE) {
            echo '<div class="wrap"><h1>Nota non trovata</h1></div>';

            return;
        }
        $n    = Plugin::format($post);
        $view = add_query_arg('dcn', 'n', home_url($n['path'])) . '#dcn-' . $n['id'];
        ?>
        <div class="wrap">
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
                        <textarea name="text" rows="4" class="large-text" placeholder="Rispondi al cliente…" required></textarea>
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
        if ($text !== '') {
            $rid = wp_insert_post(['post_type' => Plugin::REPLY, 'post_status' => 'publish', 'post_parent' => $id, 'post_title' => mb_substr($text, 0, 60), 'post_content' => $text]);
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
