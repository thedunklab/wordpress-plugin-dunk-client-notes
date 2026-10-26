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
        register_rest_route(self::NS, '/contacts', [
            ['methods' => 'GET', 'callback' => [self::class, 'contacts_list'], 'permission_callback' => [self::class, 'can_use']],
            ['methods' => 'POST', 'callback' => [self::class, 'contacts_add'], 'permission_callback' => [self::class, 'can_use']],
        ]);
        register_rest_route(self::NS, '/ready', [
            ['methods' => 'GET', 'callback' => [self::class, 'ready_state'], 'permission_callback' => [self::class, 'can_use']],
            ['methods' => 'POST', 'callback' => [self::class, 'ready_send'], 'permission_callback' => [self::class, 'can_use']],
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
        Plugin::notify('Nuova nota #' . $n . ' su ' . $path, "$author ha scritto:\n\n$text\n\nApri la nota: $link\nGestisci: " . admin_url('admin.php?page=dcn-notes&note=' . $id), [
            'title'   => 'Nuova nota #' . $n,
            'intro'   => "$author ha scritto una nota sulla pagina $path.",
            'items'   => [['n' => $n, 'author' => $author, 'text' => $text]],
            'buttons' => [['label' => 'Apri la nota', 'url' => $link, 'primary' => true], ['label' => 'Gestisci in bacheca', 'url' => admin_url('admin.php?page=dcn-notes&note=' . $id)]],
        ]);

        self::tag($r, get_post($id), $id, $author, $text, false);

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

        self::tag($r, $note, $id, $author, $text, true);

        if (!self::is_team()) {
            $n = (int) get_post_meta($note->ID, '_dcn_n', true);
            Plugin::notify('Risposta alla nota #' . $n, "$author ha risposto:\n\n$text\n\nGestisci: " . admin_url('admin.php?page=dcn-notes&note=' . $note->ID), [
                'title'   => 'Risposta alla nota #' . $n,
                'intro'   => "$author ha risposto alla nota #$n.",
                'items'   => [['n' => $n, 'author' => $author, 'text' => $text]],
                'buttons' => [['label' => 'Gestisci in bacheca', 'url' => admin_url('admin.php?page=dcn-notes&note=' . $note->ID), 'primary' => true]],
            ]);
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

    /* ---- persone esterne da segnalare ---- */

    public static function contacts_list(): \WP_REST_Response
    {
        return new \WP_REST_Response(Plugin::contacts());
    }

    public static function contacts_add(\WP_REST_Request $r)
    {
        if ($e = self::throttle()) {
            return $e;
        }
        $name  = mb_substr(trim(sanitize_text_field(wp_unslash((string) $r->get_param('name')))), 0, 80);
        $email = sanitize_email((string) $r->get_param('email'));
        if ($name === '' || !is_email($email)) {
            return new \WP_Error('dcn_contact', 'Inserisci nome e cognome e un indirizzo email valido.', ['status' => 400]);
        }
        $list = Plugin::contacts();
        $id   = substr(md5(strtolower($email)), 0, 10);
        $list = array_values(array_filter($list, static fn ($c) => $c['id'] !== $id));
        if (count($list) >= 50) {
            return new \WP_Error('dcn_contact_max', 'Hai raggiunto il numero massimo di persone (50).', ['status' => 400]);
        }
        $list[] = ['id' => $id, 'name' => $name, 'email' => strtolower($email)];
        update_option('dcn_contacts', $list, false);

        return new \WP_REST_Response($list, 201);
    }

    /**
     * Segnala una nota o una risposta alle persone esterne scelte (`tag` = id dei contatti, max 5): una email a testa.
     * Salva i nomi sulla nota/risposta e li restituisce.
     */
    private static function tag(\WP_REST_Request $r, \WP_Post $note, int $post_id, string $author, string $text, bool $is_reply): void
    {
        $ids = array_slice(array_filter(array_map('sanitize_key', (array) $r->get_param('tag'))), 0, 5);
        if (!$ids) {
            return;
        }
        $by = [];
        foreach (Plugin::contacts() as $c) {
            $by[$c['id']] = $c;
        }
        $n     = (int) get_post_meta($note->ID, '_dcn_n', true);
        $path  = (string) get_post_meta($note->ID, '_dcn_path', true);
        $link  = add_query_arg('dcn', 'n', trailingslashit(home_url($path))) . '#dcn-' . $note->ID;
        $shot  = (string) get_post_meta($note->ID, '_dcn_shot', true);
        $image = $shot !== '' ? trailingslashit(wp_get_upload_dir()['basedir']) . $shot : '';
        $names = [];
        foreach ($ids as $id) {
            if (!isset($by[$id])) {
                continue;
            }
            $c       = $by[$id];
            $names[] = $c['name'];
            $title   = $is_reply ? "Una risposta alla nota #$n" : "Una nota per te: #$n";
            $intro   = "Ciao {$c['name']},\n$author ti ha segnalato " . ($is_reply ? "una risposta alla nota #$n" : "la nota #$n") . " sulla pagina $path.";
            Mail::send($c['email'], '[' . Plugin::label() . '] ' . $title, [
                'project' => Plugin::label(),
                'title'   => $title,
                'intro'   => $intro,
                'items'   => [['n' => $n, 'author' => $author, 'text' => $text]],
                'image'   => $is_reply ? '' : $image,
                'buttons' => [['label' => 'Apri la pagina nel punto della nota', 'url' => $link, 'primary' => true]],
                'footer'  => 'Per aprire la pagina serve la password di accesso allo staging: chiedila a chi ti ha scritto. · DUNK Client Notes',
            ], "$intro\n\n$text\n\nApri la pagina: $link\n");
        }
        if ($names) {
            update_post_meta($post_id, '_dcn_tagged', $names);
        }
    }

    /** GET /ready?path=/pagina/ → ultimo avviso «note pronte» inviato per quella pagina (se c'è). */
    public static function ready_state(\WP_REST_Request $r): \WP_REST_Response
    {
        return new \WP_REST_Response(self::ready_info(Plugin::normalize_path((string) $r->get_param('path'))));
    }

    /**
     * POST /ready {path} → una sola email per pagina al destinatario scelto nelle impostazioni, con l'elenco delle note aperte.
     * Si può rinviare (dopo nuove note); la stessa pagina non può partire più di una volta ogni 2 minuti.
     */
    public static function ready_send(\WP_REST_Request $r)
    {
        $users = Plugin::ready_users();
        if (!$users) {
            return new \WP_Error('dcn_nouser', 'Il destinatario dell\'avviso non è configurato.', ['status' => 409]);
        }
        if ($e = self::throttle()) {
            return $e;
        }

        $path  = Plugin::normalize_path((string) $r->get_param('path'));
        $ready = (array) get_option('dcn_ready', []);
        if (isset($ready[$path]['at']) && time() - (int) $ready[$path]['at'] < 2 * MINUTE_IN_SECONDS) {
            return new \WP_Error('dcn_wait', 'Avviso appena inviato per questa pagina.', ['status' => 429]);
        }

        $notes = get_posts(['post_type' => Plugin::NOTE, 'post_status' => 'publish', 'posts_per_page' => 200, 'orderby' => 'date', 'order' => 'ASC',
            'meta_query' => [['key' => '_dcn_path', 'value' => $path], ['key' => '_dcn_status', 'value' => 'open']]]);
        if (!$notes) {
            return new \WP_Error('dcn_empty', 'Su questa pagina non ci sono note aperte.', ['status' => 400]);
        }

        $author = self::author($r);
        $prev   = array_map('intval', (array) ($ready[$path]['ids'] ?? []));
        $ids    = [];
        $lines  = [];
        $items  = [];
        foreach ($notes as $p) {
            $ids[]   = $p->ID;
            $items[] = ['n' => (int) get_post_meta($p->ID, '_dcn_n', true), 'author' => (string) get_post_meta($p->ID, '_dcn_author', true), 'text' => mb_substr(str_replace(["\r", "\n"], ' ', $p->post_content), 0, 140), 'new' => $prev && !in_array($p->ID, $prev, true)];
            $lines[] = sprintf('#%d%s · %s: %s', (int) get_post_meta($p->ID, '_dcn_n', true), $prev && !in_array($p->ID, $prev, true) ? ' (nuova)' : '', get_post_meta($p->ID, '_dcn_author', true), mb_substr(str_replace(["\r", "\n"], ' ', $p->post_content), 0, 140));
        }
        $count = count($notes);
        $link  = add_query_arg('dcn', 'n', trailingslashit(home_url($path)));
        $list  = admin_url('admin.php?page=dcn-notes&status=open&path=' . rawurlencode($path));

        $body = "Ciao,\n\n$author ha segnalato che le note della pagina $path sono pronte per essere messe in lavorazione (" . ($count === 1 ? '1 nota aperta' : "$count note aperte") . ").\n\n"
            . implode("\n", $lines)
            . "\n\nApri la pagina con le note: $link\nElenco in bacheca: $list\n";
        $sent = Mail::send(wp_list_pluck($users, 'user_email'), '[' . Plugin::label() . '] Note pronte: ' . $path . ' (' . $count . ')', [
            'project' => Plugin::label(),
            'title'   => 'Note pronte: ' . $path,
            'intro'   => "Ciao,\n$author ha segnalato che le note di questa pagina sono pronte per essere messe in lavorazione (" . ($count === 1 ? '1 nota aperta' : "$count note aperte") . ').',
            'items'   => $items,
            'buttons' => [['label' => 'Apri la pagina con le note', 'url' => $link, 'primary' => true], ['label' => 'Elenco in bacheca', 'url' => $list]],
        ], $body);
        if (!$sent) {
            return new \WP_Error('dcn_mail', 'Non è stato possibile inviare l\'email.', ['status' => 500]);
        }

        $ready[$path] = ['at' => time(), 'by' => $author, 'to' => implode(', ', wp_list_pluck($users, 'display_name')), 'ids' => $ids];
        update_option('dcn_ready', $ready, false);

        return new \WP_REST_Response(self::ready_info($path), 201);
    }

    /** Stato dell'avviso per una pagina: quando, da chi, a chi e quante note aperte sono arrivate dopo. */
    private static function ready_info(string $path): array
    {
        $row = ((array) get_option('dcn_ready', []))[$path] ?? null;
        if (!$row) {
            return ['sent' => false];
        }
        $open = get_posts(['post_type' => Plugin::NOTE, 'post_status' => 'publish', 'posts_per_page' => 200, 'fields' => 'ids',
            'meta_query' => [['key' => '_dcn_path', 'value' => $path], ['key' => '_dcn_status', 'value' => 'open']]]);

        return [
            'sent'  => true,
            'date'  => wp_date('j M Y, H:i', (int) $row['at']),
            'by'    => (string) $row['by'],
            'to'    => (string) $row['to'],
            'count' => count((array) $row['ids']),
            'fresh' => count(array_diff($open, array_map('intval', (array) $row['ids']))),
        ];
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
