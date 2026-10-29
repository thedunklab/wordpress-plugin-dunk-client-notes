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
        register_rest_route(self::NS, '/notes/(?P<id>\d+)/anchor', [
            ['methods' => 'POST', 'callback' => [self::class, 'anchor'], 'permission_callback' => static fn () => Plugin::enabled() && current_user_can('edit_posts')],
        ]);
        register_rest_route(self::NS, '/notes/(?P<id>\d+)/status', [
            ['methods' => 'POST', 'callback' => [self::class, 'status'], 'permission_callback' => static fn () => Plugin::enabled() && current_user_can('edit_posts')],
        ]);
        register_rest_route(self::NS, '/items/(?P<id>\d+)', [
            ['methods' => 'POST', 'callback' => [self::class, 'edit'], 'permission_callback' => [self::class, 'can_use']],
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

        $id = Plugin::insert([
            'post_type'    => Plugin::NOTE,
            'post_status'  => 'publish',
            'post_title'   => mb_substr($text, 0, 60),
            'post_content' => $text,
        ]);
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
            'sel2'     => mb_substr(sanitize_text_field((string) $r->get_param('sel2')), 0, 500),
            'anchor'   => wp_slash(self::anchor_json($r->get_param('anchor'))), // update_post_meta toglie le barre: il JSON le contiene
            'rx'       => self::unit($r->get_param('rx')),
            'ry'       => self::unit($r->get_param('ry')),
            'px'       => self::unit($r->get_param('px')),
            'py'       => max(0, (float) $r->get_param('py')),
            'vw'       => (int) $r->get_param('vw'),
            'ua'       => mb_substr(sanitize_text_field((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 200),
            'group'    => self::group_id($r),
            'contact'  => self::contact_id($r),
        ];
        foreach ($meta as $k => $v) {
            update_post_meta($id, '_dcn_' . $k, $v);
        }
        $key = self::key($id);
        $pin = self::is_team() ? Plugin::TEAM_COLOR : (Plugin::group($meta['group'])['color'] ?? '');
        if ($shot = self::save_shot((string) $r->get_param('screenshot'), $n, (float) $r->get_param('shot_fx'), (float) $r->get_param('shot_fy'), $pin)) {
            update_post_meta($id, '_dcn_shot', $shot);
        }

        $link = add_query_arg('dcn', 'n', trailingslashit(home_url($path))) . '#dcn-' . $id;
        Plugin::notify('Nuova nota #' . $n . ' su ' . $path, "$author ha scritto:\n\n$text\n\nApri la nota: $link\nGestisci: " . admin_url('admin.php?page=dcn-notes&note=' . $id), [
            'title'   => 'Nuova nota #' . $n,
            'intro'   => "$author ha scritto una nota sulla pagina $path.",
            'items'   => [['n' => $n, 'author' => $author, 'text' => $text]],
            'buttons' => [['label' => 'Apri la nota', 'url' => $link, 'primary' => true], ['label' => 'Gestisci in bacheca', 'url' => admin_url('admin.php?page=dcn-notes&note=' . $id)]],
        ]);

        self::tag($r, get_post($id), $id, $author, $text, 'note');

        return new \WP_REST_Response(Plugin::format(get_post($id)) + ['key' => $key], 201);
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
        $text   = self::text((string) $r->get_param('text'));
        $status = (string) (get_post_meta($note->ID, '_dcn_status', true) ?: 'open');
        $kind   = 'reply';
        // il team può rispondere senza testo se ha cambiato lo stato (es. «Risolta»): la risposta è solo l'avviso del nuovo stato
        if ($text === '' && self::is_team() && Plugin::status_text($status) !== '') {
            $text = Plugin::status_text($status);
            $kind = $status;
        }
        if ($text === '') {
            return new \WP_Error('dcn_text', 'Scrivi una risposta.', ['status' => 400]);
        }

        $author = self::author($r);
        $id     = Plugin::insert(['post_type' => Plugin::REPLY, 'post_status' => 'publish', 'post_parent' => $note->ID, 'post_title' => mb_substr($text, 0, 60), 'post_content' => $text]);
        if (is_wp_error($id)) {
            return $id;
        }
        update_post_meta($id, '_dcn_author', $author);
        update_post_meta($id, '_dcn_source', self::is_team() ? 'team' : 'client');
        update_post_meta($id, '_dcn_group', self::group_id($r));
        update_post_meta($id, '_dcn_contact', self::contact_id($r));
        $key = self::key($id);

        self::tag($r, $note, $id, $author, $text, $kind);

        if (!self::is_team()) {
            $n = (int) get_post_meta($note->ID, '_dcn_n', true);
            Plugin::notify('Risposta alla nota #' . $n, "$author ha risposto:\n\n$text\n\nGestisci: " . admin_url('admin.php?page=dcn-notes&note=' . $note->ID), [
                'title'   => 'Risposta alla nota #' . $n,
                'intro'   => "$author ha risposto alla nota #$n.",
                'items'   => [['n' => $n, 'author' => $author, 'text' => $text]],
                'buttons' => [['label' => 'Gestisci in bacheca', 'url' => admin_url('admin.php?page=dcn-notes&note=' . $note->ID), 'primary' => true]],
            ]);
        }

        return new \WP_REST_Response(Plugin::format($note) + ['key' => $key, 'reply' => $id], 201);
    }

    /**
     * POST /items/{id} {text, key} → corregge il testo di una nota o di una risposta, solo finché la nota è aperta.
     * Può farlo chi l'ha scritta (con la chiave ricevuta all'invio, che il suo browser conserva) o chi è nel team.
     */
    public static function edit(\WP_REST_Request $r)
    {
        $post = get_post((int) $r['id']);
        if (!$post || !in_array($post->post_type, [Plugin::NOTE, Plugin::REPLY], true)) {
            return new \WP_Error('dcn_404', 'Nota non trovata.', ['status' => 404]);
        }
        $note = $post->post_type === Plugin::NOTE ? $post : get_post($post->post_parent);
        if (!$note || (get_post_meta($note->ID, '_dcn_status', true) ?: 'open') !== 'open') {
            return new \WP_Error('dcn_closed', 'Si può modificare solo finché la nota è aperta.', ['status' => 409]);
        }
        $hash = (string) get_post_meta($post->ID, '_dcn_key', true);
        $key  = (string) $r->get_param('key');
        if (!self::is_team() && ($hash === '' || $key === '' || !hash_equals($hash, hash('sha256', $key)))) {
            return new \WP_Error('dcn_forbidden', 'Puoi modificare solo quello che hai scritto tu, dallo stesso browser.', ['status' => 403]);
        }
        if ($e = self::throttle()) {
            return $e;
        }
        $text = self::text((string) $r->get_param('text'));
        if ($text === '') {
            return new \WP_Error('dcn_text', 'Il testo non può essere vuoto.', ['status' => 400]);
        }
        kses_remove_filters();
        $res = wp_update_post(wp_slash(['ID' => $post->ID, 'post_content' => $text, 'post_title' => mb_substr($text, 0, 60)]), true);
        kses_init();
        if (is_wp_error($res)) {
            return $res;
        }
        update_post_meta($post->ID, '_dcn_edited', time());

        return new \WP_REST_Response(Plugin::format($note));
    }

    /** POST /notes/{id}/anchor (team): aggiorna selettore e impronta dell'elemento (nota vecchia senza impronta o elemento ritrovato altrove). */
    public static function anchor(\WP_REST_Request $r)
    {
        $note = get_post((int) $r['id']);
        $json = self::anchor_json($r->get_param('anchor'));
        if (!$note || $note->post_type !== Plugin::NOTE || $json === '') {
            return new \WP_Error('dcn_400', 'Richiesta non valida.', ['status' => 400]);
        }
        update_post_meta($note->ID, '_dcn_selector', mb_substr(sanitize_text_field((string) $r->get_param('selector')), 0, 500));
        update_post_meta($note->ID, '_dcn_sel2', mb_substr(sanitize_text_field((string) $r->get_param('sel2')), 0, 500));
        update_post_meta($note->ID, '_dcn_anchor', wp_slash($json));

        return new \WP_REST_Response(Plugin::format($note));
    }

    /** Impronta dell'elemento: struttura attesa, testi ripuliti e lunghezza limitata. '' se non valida. */
    private static function anchor_json($raw): string
    {
        if (!is_array($raw) || empty($raw['tag'])) {
            return '';
        }
        $clean = static function ($v) use (&$clean) {
            if (is_array($v)) {
                return array_slice(array_map($clean, $v), 0, 12, true);
            }

            return is_string($v) ? mb_substr(sanitize_text_field($v), 0, 120) : (is_numeric($v) ? $v : null);
        };
        $json = (string) wp_json_encode($clean($raw));

        return strlen($json) <= 4000 ? $json : '';
    }

    public static function status(\WP_REST_Request $r)
    {
        $note   = get_post((int) $r['id']);
        $status = (string) $r->get_param('status');
        if (!$note || $note->post_type !== Plugin::NOTE || !in_array($status, self::STATUSES, true)) {
            return new \WP_Error('dcn_400', 'Richiesta non valida.', ['status' => 400]);
        }
        $sent = Plugin::change_status($note, $status, array_map('strval', (array) $r->get_param('notify')), self::text((string) $r->get_param('text')));

        return new \WP_REST_Response(Plugin::format($note) + ['notified' => $sent]);
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
        $name  = mb_substr(trim(sanitize_text_field((string) $r->get_param('name'))), 0, 80);
        $email = sanitize_email((string) $r->get_param('email'));
        if ($name === '' || !is_email($email)) {
            return new \WP_Error('dcn_contact', 'Inserisci nome e cognome e un indirizzo email valido.', ['status' => 400]);
        }
        // dal sito la persona resta in attesa finché il team non la conferma (in bacheca → Persone esterne)
        $c = Plugin::add_contact($name, $email, self::is_team());
        if (!$c) {
            return new \WP_Error('dcn_contact_max', 'Hai raggiunto il numero massimo di persone (50).', ['status' => 400]);
        }

        return new \WP_REST_Response(['contacts' => Plugin::contacts(), 'id' => $c['id']], 201);
    }

    /**
     * Segnala una nota o una risposta alle persone esterne scelte (`tag` = id dei contatti, max 5): una email a testa.
     * Salva i nomi sulla nota/risposta e li restituisce.
     */
    private static function tag(\WP_REST_Request $r, \WP_Post $note, int $post_id, string $author, string $text, string $kind): void
    {
        $ids = array_slice(array_map('strval', (array) $r->get_param('tag')), 0, 5);
        if ($ids) {
            Plugin::notify_contacts($note, $post_id, $ids, $author, $text, $kind);
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
        return mb_substr(trim(sanitize_textarea_field($raw)), 0, self::MAX_TEXT);
    }

    private static function author(\WP_REST_Request $r): string
    {
        if (self::is_team()) {
            return wp_get_current_user()->display_name;
        }
        // chi scrive si identifica con una persona della rubrica: il nome è il suo, non un testo libero
        if ($c = Plugin::contact(self::contact_id($r))) {
            return $c['name'];
        }
        $name = mb_substr(trim(sanitize_text_field((string) $r->get_param('author'))), 0, 60);

        return $name !== '' ? $name : 'Cliente';
    }

    /** Gruppo di lavoro scelto da chi scrive ('' se nessuno o non più esistente). */
    private static function group_id(\WP_REST_Request $r): string
    {
        // chi è nel team scrive come team, mai con un gruppo del cliente
        if (self::is_team()) {
            return '';
        }
        $id = sanitize_key((string) $r->get_param('group'));
        if ($id !== '' && Plugin::group($id)) {
            return $id;
        }
        $groups = Plugin::groups();

        return count($groups) === 1 ? $groups[0]['id'] : '';
    }

    /** Contatto della rubrica con cui chi scrive si è identificato ('' se nessuno). */
    private static function contact_id(\WP_REST_Request $r): string
    {
        $id = sanitize_key((string) $r->get_param('contact'));

        return $id !== '' && Plugin::contact($id) ? $id : '';
    }

    /** Chiave per correggere in seguito quello che si è scritto: al browser va la chiave, nel database solo l'hash. */
    private static function key(int $post_id): string
    {
        $key = wp_generate_password(24, false);
        update_post_meta($post_id, '_dcn_key', hash('sha256', $key));

        return $key;
    }

    private static function unit($v): float
    {
        return min(1.0, max(0.0, (float) $v));
    }

    /** Salva lo screenshot (data URL JPEG) in uploads/dunk-notes/ e restituisce il percorso relativo a uploads; '' se non valido. */
    private static function save_shot(string $data, int $n, float $fx = -1, float $fy = -1, string $color = ''): string
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

        $bin = self::stamp($bin, $n, $fx, $fy, $color);

        $up  = wp_get_upload_dir();
        $dir = trailingslashit($up['basedir']) . 'dunk-notes';
        wp_mkdir_p($dir);
        $name = sprintf('nota-%d-%s.jpg', $n, wp_generate_password(8, false));
        if (file_put_contents($dir . '/' . $name, $bin) === false) {
            return '';
        }

        return 'dunk-notes/' . $name;
    }

    /**
     * Disegna sullo screenshot la puntina numerata (stessa forma e colori di quella sul sito) con la punta nel
     * punto cliccato: se poi le puntine si spostano, lo screenshot dice a cosa si riferiva il cliente.
     * $fx/$fy sono la posizione del clic come frazione della larghezza/altezza; $color è il colore del gruppo (#rrggbb, vuoto = giallo DUNK).
     * Senza GD o su errore restituisce l'immagine com'è.
     */
    private static function stamp(string $bin, int $n, float $fx, float $fy, string $color = ''): string
    {
        $font = DCN_DIR . 'assets/fonts/dm-sans-latin-700.ttf';
        if ($fx < 0 || $fx > 1 || $fy < 0 || $fy > 1 || !function_exists('imagettftext') || !function_exists('imagecreatefromstring') || !is_readable($font)) {
            return $bin;
        }
        $img = @imagecreatefromstring($bin);
        if (!$img) {
            return $bin;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $x = $fx * $w;
        $y = $fy * $h;
        $r = max(13.0, min(22.0, $w / 1280 * 16 + 4));   // raggio del cerchio, in px dell'immagine
        $b = max(2.0, $r / 6);                            // bordo scuro
        $k = 4;                                           // sovracampionamento per i bordi lisci
        $tip = $r * 1.414;                                // dal centro del cerchio alla punta
        $sw = (int) ceil(($r + $b) * 2 * $k);
        $sh = (int) ceil(($r + $b + $tip + $b * 1.414 + 1) * $k); // fino alla punta del bordo
        $spr = imagecreatetruecolor($sw, $sh);
        imagealphablending($spr, false);
        imagesavealpha($spr, true);
        imagefill($spr, 0, 0, imagecolorallocatealpha($spr, 0, 0, 0, 127));
        imagealphablending($spr, true);
        $cx = $sw / 2;
        $cy = ($r + $b) * $k;
        $drop = static function (float $rad, int $col) use ($spr, $cx, $cy, $k, $tip, $r): void {
            $rr = $rad * $k;
            $d = $tip * $k * ($rad / $r);
            imagefilledellipse($spr, (int) $cx, (int) $cy, (int) (2 * $rr), (int) (2 * $rr), $col);
            $a = M_PI / 4;
            imagefilledpolygon($spr, [
                (int) ($cx - $rr * cos($a)), (int) ($cy + $rr * sin($a)),
                (int) ($cx + $rr * cos($a)), (int) ($cy + $rr * sin($a)),
                (int) $cx, (int) ($cy + $d),
            ], $col);
        };
        $drop($r + $b, imagecolorallocate($spr, 18, 18, 18));
        $fill = preg_match('/^#[0-9a-f]{6}$/i', $color) ? sscanf(substr($color, 1), '%02x%02x%02x') : [253, 224, 0];
        $ink  = $color !== '' ? sscanf(substr(Plugin::ink($color), 1), '%02x%02x%02x') : [26, 22, 0];
        $drop($r, imagecolorallocate($spr, $fill[0], $fill[1], $fill[2]));
        $label = (string) $n;
        $size = $r * $k * (strlen($label) > 2 ? 0.8 : 1.0);
        $box = imagettfbbox($size, 0, $font, $label);
        if ($box) {
            imagettftext($spr, $size, 0, (int) ($cx - ($box[2] + $box[0]) / 2), (int) ($cy - ($box[7] + $box[1]) / 2), imagecolorallocate($spr, $ink[0], $ink[1], $ink[2]), $font, $label);
        }
        $dw = (int) round($sw / $k);
        $dh = (int) round($sh / $k);
        imagealphablending($img, true);
        imagecopyresampled($img, $spr, (int) round($x - $dw / 2), (int) round($y - $dh), 0, 0, $dw, $dh, $sw, $sh);
        ob_start();
        imagejpeg($img, null, 82);
        $out = (string) ob_get_clean();

        return $out !== '' ? $out : $bin;
    }
}
