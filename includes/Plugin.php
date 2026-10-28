<?php

declare(strict_types=1);

namespace Dunk\ClientNotes;

/**
 * Tipi di contenuto, attivazione, caricamento dell'interfaccia sul sito e funzioni di supporto.
 *
 * Le note sono un tipo di contenuto non pubblico (`dcn_note`), le risposte un altro (`dcn_reply`, figlio della nota).
 * L'interfaccia sul sito (puntine, pannello, screenshot) si carica solo se il plugin è ATTIVO nelle impostazioni
 * (o con la costante DUNK_NOTES_ENABLED in wp-config.php): pensata per lo staging, mai per la produzione.
 */
class Plugin
{
    public const NOTE  = 'dcn_note';
    public const REPLY = 'dcn_reply';

    public static function init(): void
    {
        add_action('init', [self::class, 'register_types']);
        add_action('rest_api_init', [Rest::class, 'register']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue']);
        add_action('admin_bar_menu', [self::class, 'admin_bar'], 90);
        add_filter('wp_robots', static fn (array $r): array => self::enabled() ? array_merge($r, ['noindex' => true, 'nofollow' => true]) : $r);
    }

    /** Il sistema è attivo? La costante in wp-config.php ha la precedenza sull'impostazione. */
    public static function enabled(): bool
    {
        if (defined('DUNK_NOTES_ENABLED')) {
            return (bool) DUNK_NOTES_ENABLED;
        }

        return (bool) get_option('dcn_enabled', 0);
    }

    public static function register_types(): void
    {
        foreach ([self::NOTE, self::REPLY] as $type) {
            register_post_type($type, [
                'labels'              => ['name' => $type === self::NOTE ? 'Note del cliente' : 'Risposte'],
                'public'              => false,
                'show_ui'             => false,
                'show_in_rest'        => false,
                'exclude_from_search' => true,
                'supports'            => ['title', 'editor'],
                'rewrite'             => false,
                'query_var'           => false,
            ]);
        }
    }

    /** Chiave che autorizza chi ha caricato una pagina del sito (protetto dalla password dello staging) a scrivere note. */
    public static function token(): string
    {
        return substr(hash_hmac('sha256', 'dcn|' . home_url(), wp_salt('auth')), 0, 32);
    }

    /** @font-face di DM Sans (file nel plugin, nessuna richiesta a servizi esterni). */
    public static function font_face(): string
    {
        return "@font-face{font-family:'DM Sans';font-style:normal;font-weight:100 1000;font-display:swap;src:url('" . esc_url(DCN_URL . 'assets/fonts/dm-sans-latin-wght-normal.woff2') . "') format('woff2');}";
    }

    public static function label(): string
    {
        return (string) (get_option('dcn_label') ?: get_bloginfo('name'));
    }

    public static function enqueue(): void
    {
        if (!self::enabled() || is_customize_preview() || (isset($_GET['dcn']) && $_GET['dcn'] === 'off')) {
            return;
        }

        $user = wp_get_current_user();
        $team = current_user_can('edit_posts');

        wp_enqueue_script('dcn-html2canvas', DCN_URL . 'assets/vendor/html2canvas-pro.min.js', [], '2.5.0', true);
        wp_enqueue_script('dcn', DCN_URL . 'assets/dcn.js', ['dcn-html2canvas'], DCN_VERSION . '.' . filemtime(DCN_DIR . 'assets/dcn.js'), true);

        $css = (string) file_get_contents(DCN_DIR . 'assets/dcn.css');
        wp_add_inline_script('dcn', 'window.dcnConfig=' . wp_json_encode([
            'rest'   => esc_url_raw(rest_url('dunk-notes/v1')),
            'nonce'  => wp_create_nonce('wp_rest'),
            'token'  => self::token(),
            'label'  => self::label(),
            'team'   => $team,
            'user'   => $team ? $user->display_name : '',
            'admin'  => $team ? admin_url('admin.php?page=dcn-notes') : '',
            'ready'  => !$team && (bool) self::ready_users(), // lo segnala il cliente: chi è nel team lo riceve
            'css'    => $css,
            'font'   => DCN_URL . 'assets/fonts/dm-sans-latin-wght-normal.woff2',
        ]) . ';', 'before');
    }

    public static function admin_bar(\WP_Admin_Bar $bar): void
    {
        if (!self::enabled() || !current_user_can('edit_posts')) {
            return;
        }

        $open = self::count('open');
        $bar->add_node([
            'id'    => 'dcn',
            'title' => '<span class="ab-icon dashicons dashicons-format-chat" style="margin-top:2px"></span> Note cliente' . ($open ? ' (' . $open . ')' : ''),
            'href'  => admin_url('admin.php?page=dcn-notes'),
        ]);
    }

    /**
     * Persone esterne al team aggiunte dal pannello (nome ed email), da segnalare su note e risposte.
     *
     * @return array<int, array{id: string, name: string, email: string}>
     */
    public static function contacts(): array
    {
        return array_values(array_filter((array) get_option('dcn_contacts', []), static fn ($c) => is_array($c) && isset($c['id'], $c['name'], $c['email'])));
    }

    /** Testo automatico di una risposta senza messaggio, quando il team ha cambiato lo stato della nota. */
    public static function status_text(string $status): string
    {
        return ['resolved' => 'Nota segnata come risolta.', 'progress' => 'Nota presa in carico: in lavorazione.'][$status] ?? '';
    }

    /**
     * Salva una nota o una risposta. Il testo è già ripulito (sanitize_textarea_field) e viene mostrato sempre come testo:
     * senza questo, i filtri kses applicati a chi non ha unfiltered_html trasformano «>» in «&gt;».
     *
     * @return int|\WP_Error
     */
    public static function insert(array $post)
    {
        kses_remove_filters();
        $id = wp_insert_post(wp_slash($post), true);
        kses_init();

        return $id;
    }

    /** Numero di note con lo stato indicato ('' = tutte). */
    public static function count(string $status = ''): int
    {
        $args = ['post_type' => self::NOTE, 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => false];
        if ($status !== '') {
            $args['meta_query'] = [['key' => '_dcn_status', 'value' => $status]];
        }

        return (int) (new \WP_Query($args))->found_posts;
    }

    public static function normalize_path(string $path): string
    {
        $path = (string) wp_parse_url($path, PHP_URL_PATH);

        return trailingslashit($path === '' ? '/' : $path);
    }

    /** Una nota con le sue risposte, nel formato usato dall'interfaccia. */
    public static function format(\WP_Post $note): array
    {
        $meta  = static fn (string $k) => get_post_meta($note->ID, '_dcn_' . $k, true);
        $shot  = (string) $meta('shot');
        $up    = wp_get_upload_dir();
        $reply = [];
        foreach (get_posts(['post_type' => self::REPLY, 'post_parent' => $note->ID, 'posts_per_page' => 100, 'orderby' => 'date', 'order' => 'ASC', 'post_status' => 'publish']) as $r) {
            $reply[] = [
                'id'     => $r->ID,
                'author' => (string) get_post_meta($r->ID, '_dcn_author', true),
                'source' => (string) get_post_meta($r->ID, '_dcn_source', true) ?: 'client',
                'text'   => wp_specialchars_decode($r->post_content, ENT_QUOTES),
                'date'   => self::human_date($r),
                'tagged' => array_values(array_filter((array) get_post_meta($r->ID, '_dcn_tagged', true))),
            ];
        }

        return [
            'id'       => $note->ID,
            'n'        => (int) $meta('n'),
            'author'   => (string) $meta('author'),
            'source'   => (string) $meta('source') ?: 'client',
            'text'     => wp_specialchars_decode($note->post_content, ENT_QUOTES),
            'status'   => (string) $meta('status') ?: 'open',
            'path'     => (string) $meta('path'),
            'url'      => (string) $meta('url'),
            'selector' => (string) $meta('selector'),
            'sel2'     => (string) $meta('sel2'),
            'anchor'   => json_decode((string) $meta('anchor'), true) ?: null,
            'rx'       => (float) $meta('rx'),
            'ry'       => (float) $meta('ry'),
            'px'       => (float) $meta('px'),
            'py'       => (float) $meta('py'),
            'vw'       => (int) $meta('vw'),
            'shot'     => $shot !== '' ? trailingslashit($up['baseurl']) . $shot : '',
            'date'     => self::human_date($note),
            'tagged'   => array_values(array_filter((array) $meta('tagged'))),
            'replies'  => $reply,
        ];
    }

    private static function human_date(\WP_Post $p): string
    {
        return wp_date('j M Y, H:i', strtotime($p->post_date_gmt . ' UTC') ?: null);
    }

    /**
     * Utenti WordPress che ricevono l'avviso «note pronte» (Impostazioni); vuoto se nessuno è scelto.
     * Legge anche la vecchia impostazione a utente singolo (`dcn_notify_user`).
     *
     * @return \WP_User[]
     */
    public static function ready_users(): array
    {
        $ids = (array) get_option('dcn_notify_users', []);
        if (!$ids && ($old = (int) get_option('dcn_notify_user', 0))) {
            $ids = [$old];
        }
        $users = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $u = $id ? get_userdata($id) : false;
            if ($u && is_email($u->user_email)) {
                $users[] = $u;
            }
        }

        return $users;
    }

    /** Invia un'email al team (se attivo nelle impostazioni). */
    public static function notify(string $subject, string $body, array $spec = []): void
    {
        if (!get_option('dcn_mail', 1)) {
            return;
        }
        $to = array_filter(array_map('trim', explode(',', (string) get_option('dcn_emails', get_option('admin_email')))), 'is_email');
        if ($to) {
            $spec ? Mail::send($to, '[' . self::label() . '] ' . $subject, $spec + ['project' => self::label()], $body) : wp_mail($to, '[' . self::label() . '] ' . $subject, $body);
        }
    }
}
