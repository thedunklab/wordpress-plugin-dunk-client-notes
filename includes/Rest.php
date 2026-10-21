<?php

declare(strict_types=1);

namespace Dunk\ClientNotes;

/**
 * API REST (namespace dunk-notes/v1). Chi usa il sito dello staging (già protetto da password) ha la chiave
 * `X-DCN-Token` stampata nella pagina; chi è loggato in WordPress come redattore scrive come «team» (cookie + nonce).
 * Ogni scrittura è limitata per IP, e testi/immagini hanno dimensioni massime.
 */
class Rest
{
    private const NS         = 'dunk-notes/v1';
    private const MAX_TEXT   = 2000;
    private const MAX_SHOT   = 2_500_000; // byte della JPEG, dopo la decodifica
    private const RATE_MAX   = 40;        // scritture ogni 10 minuti per IP
    private const STATUSES   = ['open', 'progress', 'resolved'];

    public static function register(): void
    {
        register_rest_route(self::NS, '/notes', [
            ['methods' => 'GET', 'callback' => [self::class, 'list'], 'permission_callback' => [self::class, 'can_use']],
            ['methods' => 'POST', 'callback' => [self::class, 'create'], 'permission_callback' => [self::class, 'can_use']],
        ]);
        register_rest_route(self::NS, '/notes/(?P<id>\d+)/replies', [
            ['methods' => 'POST', 'callback' => [self::class, 'reply'], 'permission_callback' => [self::class, 'can_use']],
        ]);
        register_rest_route(self::NS, '/notes/(?P<id>\d+)/status', [
            ['methods' => 'POST', 'callback' => [self::class, 'status'], 'permission_callback' => static fn () => Plugin::enabled() && current_user_can('edit_posts')],
        ]);
    }

    public static function can_use(\WP_REST_Request $r): bool
    {
        if (!Plugin::enabled()) {
            return false;
        }

        return current_user_can('edit_posts') || hash_equals(Plugin::token(), (string) $r->get_header('x-dcn-token'));
    }

    private static function is_team(): bool
    {
        return current_user_can('edit_posts');
    }

    private static function throttle(): ?\WP_Error
    {
        $key = 'dcn_rl_' . md5((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $n   = (int) get_transient($key);
        if ($n >= self::RATE_MAX) {
            return new \WP_Error('dcn_rate', 'Troppe richieste: riprova fra qualche minuto.', ['status' => 429]);
        }
        set_transient($key, $n + 1, 10 * MINUTE_IN_SECONDS);

        return null;
    }

    /** GET /notes?path=/pagina/ → note di quella pagina; senza path: tutte (più recenti prima). */
    public static function list(\WP_REST_Request $r): \WP_REST_Response
    {
        $args = ['post_type' => Plugin::NOTE, 'post_status' => 'publish', 'posts_per_page' => 200, 'orderby' => 'date', 'order' => 'ASC'];
        if ($r->get_param('path')) {
            $args['meta_query'] = [['key' => '_dcn_path', 'value' => Plugin::normalize_path((string) $r->get_param('path'))]];
        }

        return new \WP_REST_Response(array_map([Plugin::class, 'format'], get_posts($args)));
    }

    public static function create(\WP_REST_Request $r)
    {
        if ($e = self::throttle()) {
            return $e;
        }

        $text = self::text((string) $r->get_param('text'));
        if ($text === '') {
            return new \WP_Error('dcn_text', 'Scrivi una nota.', ['status' => 400]);
        }

        $author = self::author($r);
        $path   = Plugin::normalize_path((string) $r->get_param('path'));
        $n      = (int) get_option('dcn_counter', 0) + 1;

        $id = wp_insert_post([
            'post_type'    => Plugin::NOTE,
            'post_status'  => 'publish',
            'post_title'   => mb_substr($text, 0, 60),
            'post_content' => $text,
        ], true);
        if (is_wp_error($id)) {
            return $id;
        }
        update_option('dcn_counter', $n, false);

        $meta = [
            'n'        => $n,
            'author'   => $author,
            'source'   => self::is_team() ? 'team' : 'client',
            'status'   => 'open',
            'path'     => $path,
            'url'      => esc_url_raw((string) $r->get_param('url')),
            'selector' => mb_substr(sanitize_text_field((string) $r->get_param('selector')), 0, 500),
            'rx'       => self::unit($r->get_param('rx')),
            'ry'       => self::unit($r->get_param('ry')),
            'px'       => self::unit($r->get_param('px')),
            'py'       => max(0, (float) $r->get_param('py')),
            'vw'       => (int) $r->get_param('vw'),
            'ua'       => mb_substr(sanitize_text_field((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 200),
        ];
        foreach ($meta as $k => $v) {
            update_post_meta($id, '_dcn_' . $k, $v);
        }
        if ($shot = self::save_shot((string) $r->get_param('screenshot'), $n)) {
            update_post_meta($id, '_dcn_shot', $shot);
        }

        $link = add_query_arg('dcn', 'n', trailingslashit(home_url($path))) . '#dcn-' . $id;
        Plugin::notify('Nuova nota #' . $n . ' su ' . $path, "$author ha scritto:\n\n$text\n\nApri la nota: $link\nGestisci: " . admin_url('admin.php?page=dcn-notes&note=' . $id));

        return new \WP_REST_Response(Plugin::format(get_post($id)), 201);
    }

    public static function reply(\WP_REST_Request $r)
    {
        $note = get_post((int) $r['id']);
        if (!$note || $note->post_type !== Plugin::NOTE) {
            return new \WP_Error('dcn_404', 'Nota non trovata.', ['status' => 404]);
        }
        if ($e = self::throttle()) {
            return $e;
        }
        $text = self::text((string) $r->get_param('text'));
        if ($text === '') {
            return new \WP_Error('dcn_text', 'Scrivi una risposta.', ['status' => 400]);
        }

        $author = self::author($r);
        $id     = wp_insert_post(['post_type' => Plugin::REPLY, 'post_status' => 'publish', 'post_parent' => $note->ID, 'post_title' => mb_substr($text, 0, 60), 'post_content' => $text], true);
        if (is_wp_error($id)) {
            return $id;
        }
        update_post_meta($id, '_dcn_author', $author);
        update_post_meta($id, '_dcn_source', self::is_team() ? 'team' : 'client');

        if (!self::is_team()) {
            $n = (int) get_post_meta($note->ID, '_dcn_n', true);
            Plugin::notify('Risposta alla nota #' . $n, "$author ha risposto:\n\n$text\n\nGestisci: " . admin_url('admin.php?page=dcn-notes&note=' . $note->ID));
        }

        return new \WP_REST_Response(Plugin::format($note), 201);
    }

    public static function status(\WP_REST_Request $r)
    {
        $note   = get_post((int) $r['id']);
        $status = (string) $r->get_param('status');
        if (!$note || $note->post_type !== Plugin::NOTE || !in_array($status, self::STATUSES, true)) {
            return new \WP_Error('dcn_400', 'Richiesta non valida.', ['status' => 400]);
        }
        update_post_meta($note->ID, '_dcn_status', $status);

        return new \WP_REST_Response(Plugin::format($note));
    }

    /* ---- supporto ---- */

    private static function text(string $raw): string
    {
        return mb_substr(trim(sanitize_textarea_field(wp_unslash($raw))), 0, self::MAX_TEXT);
    }

    private static function author(\WP_REST_Request $r): string
    {
        if (self::is_team()) {
            return wp_get_current_user()->display_name;
        }
        $name = mb_substr(trim(sanitize_text_field(wp_unslash((string) $r->get_param('author')))), 0, 60);

        return $name !== '' ? $name : 'Cliente';
    }

    private static function unit($v): float
    {
        return min(1.0, max(0.0, (float) $v));
    }

    /** Salva lo screenshot (data URL JPEG) in uploads/dunk-notes/ e restituisce il percorso relativo a uploads; '' se non valido. */
    private static function save_shot(string $data, int $n): string
    {
        if (!preg_match('~^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$~', $data, $m)) {
            return '';
        }
        $bin = base64_decode($m[1], true);
        if ($bin === false || strlen($bin) > self::MAX_SHOT) {
            return '';
        }
        $info = @getimagesizefromstring($bin);
        if (!$info || $info['mime'] !== 'image/jpeg' || $info[0] > 4000 || $info[1] > 4000) {
            return '';
        }

        $up  = wp_get_upload_dir();
        $dir = trailingslashit($up['basedir']) . 'dunk-notes';
        wp_mkdir_p($dir);
        $name = sprintf('nota-%d-%s.jpg', $n, wp_generate_password(8, false));
        if (file_put_contents($dir . '/' . $name, $bin) === false) {
            return '';
        }

        return 'dunk-notes/' . $name;
    }
}
