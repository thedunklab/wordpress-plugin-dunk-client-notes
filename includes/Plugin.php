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

    /** Gruppo usato quando il sito non ne ha di propri: tutto il cliente insieme, giallo DUNK. */
    public const DEFAULT_GROUP = ['id' => 'cliente', 'name' => 'Cliente', 'color' => '#fee000'];

    /** Chi è nel team non usa i gruppi del cliente: puntine nere con il numero giallo. */
    public const TEAM_COLOR = '#1c1c1c';
    public const TEAM_INK   = '#fde000';

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
            'version' => DCN_VERSION,
            'team'   => $team,
            'user'   => $team ? $user->display_name : '',
            'admin'  => $team ? admin_url('admin.php?page=dcn-notes') : '',
            'groups' => array_map(static fn ($g) => $g + ['ink' => self::ink($g['color'])], self::groups()),
            'teamTag' => ['name' => self::team_label(), 'color' => self::TEAM_COLOR, 'ink' => self::TEAM_INK],
            'ready'  => !$team && (bool) self::ready_users(), // lo segnala il cliente: chi è nel team lo riceve
            'autoclose' => (bool) get_option('dcn_autoclose', 1), // chiude il pannello dopo l'invio di una risposta
            'fab'    => ['side' => get_option('dcn_fab_side', 'right') === 'left' ? 'left' : 'right', 'bottom' => (int) get_option('dcn_fab_bottom', 0)], // posizione del pulsante (per non coprire altri widget, es. Iubenda)
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
     * Persone esterne al team (nome ed email): chi scrive sul sito si identifica con una di queste, e si possono
     * segnalare su note e risposte. `pending` = aggiunta dal sito e non ancora confermata dal team.
     *
     * @return array<int, array{id: string, name: string, email: string, pending: bool}>
     */
    public static function contacts(): array
    {
        $list = array_filter((array) get_option('dcn_contacts', []), static fn ($c) => is_array($c) && isset($c['id'], $c['name'], $c['email']));

        return array_values(array_map(static fn ($c) => ['id' => $c['id'], 'name' => $c['name'], 'email' => $c['email'], 'pending' => !empty($c['pending'])], $list));
    }

    /**
     * Aggiunge o aggiorna una persona della rubrica. Chi non è nel team la aggiunge in attesa di conferma;
     * un indirizzo già confermato resta confermato. Restituisce la persona, oppure null se la rubrica è piena (50).
     */
    public static function add_contact(string $name, string $email, bool $confirmed): ?array
    {
        $email = strtolower($email);
        $id    = substr(md5($email), 0, 10);
        $old   = self::contact($id);
        $list  = array_values(array_filter(self::contacts(), static fn ($c) => $c['id'] !== $id));
        if (!$old && count($list) >= 50) {
            return null;
        }
        $c      = ['id' => $id, 'name' => $name, 'email' => $email, 'pending' => !$confirmed && (!$old || $old['pending'])];
        $list[] = $c;
        update_option('dcn_contacts', $list, false);
        if ($c['pending'] && !$old) {
            $people = admin_url('admin.php?page=dcn-people');
            self::notify('Nuova persona da confermare: ' . $name, "$name ($email) si è aggiunta dal sito e aspetta la conferma.\n\nConferma o rifiuta: $people", [
                'title'   => 'Nuova persona da confermare',
                'intro'   => "$name ($email) si è aggiunta dal pannello delle note e aspetta la conferma del team. Intanto può scrivere: le sue note sono segnate «da confermare».",
                'buttons' => [['label' => 'Conferma o rifiuta', 'url' => $people, 'primary' => true]],
            ]);
        }

        return $c;
    }

    /** Chi ha scritto è una persona aggiunta dal sito e non ancora confermata dal team? */
    public static function is_pending(string $contact): bool
    {
        return $contact !== '' && !empty(self::contact($contact)['pending']);
    }

    /** Persone aggiunte dal sito e non ancora confermate. */
    public static function pending_count(): int
    {
        return count(array_filter(self::contacts(), static fn ($c) => $c['pending']));
    }

    /**
     * Gruppi di lavoro definiti nelle impostazioni (es. Marketing, Regolatorio): nome e colore delle puntine.
     *
     * @return array<int, array{id: string, name: string, color: string}>
     */
    public static function groups(): array
    {
        $list = array_values(array_filter((array) get_option('dcn_groups', []), static fn ($g) => is_array($g) && isset($g['id'], $g['name'], $g['color'])));

        return $list ?: [self::DEFAULT_GROUP];
    }

    /** Nome con cui scrive chi è nel team (Impostazioni), al posto dei gruppi del cliente. */
    public static function team_label(): string
    {
        return (string) (get_option('dcn_team_label') ?: 'DUNK');
    }

    /**
     * Gruppo da mostrare per una nota o una risposta: il team non ha gruppo (ha la sua etichetta); il cliente ha
     * il suo, oppure «Cliente» se il sito usa solo il gruppo predefinito (anche per le note scritte prima dei gruppi).
     */
    public static function group_for(string $stored, string $source): string
    {
        if ($source === 'team') {
            return '';
        }
        if ($stored !== '' && self::group($stored)) {
            return $stored;
        }
        $groups = self::groups();

        return count($groups) === 1 && $groups[0]['id'] === self::DEFAULT_GROUP['id'] ? self::DEFAULT_GROUP['id'] : '';
    }

    /** Il gruppo con quell'id, oppure null (gruppo cancellato o nessun gruppo). */
    public static function group(string $id): ?array
    {
        foreach (self::groups() as $g) {
            if ($g['id'] === $id) {
                return $g;
            }
        }

        return null;
    }

    /** Colore del testo leggibile sopra un colore di sfondo (#rrggbb): quasi nero o bianco. */
    public static function ink(string $hex): string
    {
        if (strtolower($hex) === self::TEAM_COLOR) {
            return self::TEAM_INK;
        }
        [$r, $g, $b] = sscanf(ltrim($hex, '#'), '%02x%02x%02x') + [0, 0, 0];

        return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 150 ? '#1a1600' : '#ffffff';
    }

    /** Il contatto della rubrica con quell'id, oppure null. */
    public static function contact(string $id): ?array
    {
        foreach (self::contacts() as $c) {
            if ($c['id'] === $id) {
                return $c;
            }
        }

        return null;
    }

    /**
     * Contatti da proporre come destinatari quando il team cambia lo stato di una nota:
     * chi l'ha scritta o ha risposto (se è nella rubrica) e chi è stato segnalato.
     *
     * @return string[] id dei contatti
     */
    public static function involved(\WP_Post $note): array
    {
        $ids = array_merge([(string) get_post_meta($note->ID, '_dcn_contact', true)], (array) get_post_meta($note->ID, '_dcn_tagged_ids', true));
        foreach (get_posts(['post_type' => self::REPLY, 'post_parent' => $note->ID, 'posts_per_page' => 100, 'fields' => 'ids']) as $rid) {
            $ids = array_merge($ids, [(string) get_post_meta($rid, '_dcn_contact', true)], (array) get_post_meta($rid, '_dcn_tagged_ids', true));
        }
        $known = wp_list_pluck(self::contacts(), 'id');

        return array_values(array_intersect(array_unique(array_filter(array_map('strval', $ids))), $known));
    }

    /**
     * Email a ciascun contatto scelto (max 10) per una nota, una risposta o un cambio di stato; salva sulla nota/risposta
     * nomi e id dei segnalati. $kind: note, reply, resolved, progress. $text: testo della risposta o messaggio del team.
     *
     * @param string[] $ids id dei contatti
     * @return string[] nomi delle persone avvisate
     */
    public static function notify_contacts(\WP_Post $note, int $post_id, array $ids, string $author, string $text, string $kind): array
    {
        $ids = array_slice(array_unique(array_filter(array_map('sanitize_key', $ids))), 0, 10);
        $n     = (int) get_post_meta($note->ID, '_dcn_n', true);
        $path  = (string) get_post_meta($note->ID, '_dcn_path', true);
        $link  = add_query_arg('dcn', 'n', trailingslashit(home_url($path))) . '#dcn-' . $note->ID;
        $shot  = (string) get_post_meta($note->ID, '_dcn_shot', true);
        $image = $shot !== '' ? trailingslashit(wp_get_upload_dir()['basedir']) . $shot : '';
        $state = in_array($kind, ['resolved', 'progress'], true);
        $names = [];
        $sent  = [];
        foreach ($ids as $id) {
            $c = self::contact($id);
            if (!$c) {
                continue;
            }
            $names[] = $c['name'];
            $sent[]  = $c['id'];
            $title   = ['note' => "Una nota per te: #$n", 'reply' => "Una risposta alla nota #$n", 'resolved' => "Nota #$n risolta", 'progress' => "Nota #$n in lavorazione"][$kind];
            $intro   = "Ciao {$c['name']},\n" . [
                'note'     => "$author ti ha segnalato la nota #$n sulla pagina $path.",
                'reply'    => "$author ti ha segnalato una risposta alla nota #$n sulla pagina $path.",
                'resolved' => "$author ha segnato come risolta la nota #$n sulla pagina $path.",
                'progress' => "$author ha preso in carico la nota #$n sulla pagina $path: è in lavorazione.",
            ][$kind];
            $items = [['n' => $n, 'author' => $state ? (string) get_post_meta($note->ID, '_dcn_author', true) : $author, 'text' => $state ? $note->post_content : $text]];
            // messaggio scritto dal team insieme al cambio di stato (non il testo automatico)
            if ($state && $text !== '' && $text !== self::status_text($kind)) {
                $items[] = ['n' => '↳', 'author' => $author, 'text' => $text];
            }
            $plain = $intro . "\n\n" . implode("\n\n", array_map(static fn ($i) => $i['author'] . ': ' . $i['text'], $items)) . "\n\nApri la pagina: $link\n";
            Mail::send($c['email'], '[' . self::label() . '] ' . $title, [
                'project' => self::label(),
                'title'   => $title,
                'intro'   => $intro,
                'items'   => $items,
                'image'   => $kind === 'note' || $state ? $image : '',
                'buttons' => [['label' => 'Apri la pagina nel punto della nota', 'url' => $link, 'primary' => true]],
                'footer'  => 'Per aprire la pagina serve la password di accesso allo staging: chiedila a chi ti ha scritto. · DUNK Client Notes',
            ], $plain);
        }
        if ($names) {
            update_post_meta($post_id, '_dcn_tagged', $names);
            update_post_meta($post_id, '_dcn_tagged_ids', $sent);
        }

        return $names;
    }

    /**
     * Cambio di stato da parte del team. Se la nota passa a «In lavorazione» o «Risolta» e c'è un messaggio o qualcuno
     * da avvisare, aggiunge alla conversazione una risposta del team (il messaggio o il testo automatico dello stato)
     * e manda l'email ai contatti scelti.
     *
     * @param string[] $notify id dei contatti da avvisare
     * @return string[] nomi delle persone avvisate
     */
    public static function change_status(\WP_Post $note, string $status, array $notify = [], string $text = ''): array
    {
        update_post_meta($note->ID, '_dcn_status', $status);
        if (self::status_text($status) === '' || ($text === '' && !$notify)) {
            return [];
        }
        $text   = $text !== '' ? $text : self::status_text($status);
        $author = wp_get_current_user()->display_name;
        $rid    = self::insert(['post_type' => self::REPLY, 'post_status' => 'publish', 'post_parent' => $note->ID, 'post_title' => mb_substr($text, 0, 60), 'post_content' => $text]);
        if (is_wp_error($rid)) {
            return [];
        }
        update_post_meta($rid, '_dcn_author', $author);
        update_post_meta($rid, '_dcn_source', 'team');
        update_post_meta($rid, '_dcn_kind', $status);

        return self::notify_contacts($note, $rid, $notify, $author, $text, $status);
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
                'group'  => self::group_for((string) get_post_meta($r->ID, '_dcn_group', true), (string) get_post_meta($r->ID, '_dcn_source', true) ?: 'client'),
                'kind'   => (string) get_post_meta($r->ID, '_dcn_kind', true), // '' = risposta, altrimenti cambio di stato del team
                'pending' => self::is_pending((string) get_post_meta($r->ID, '_dcn_contact', true)),
                'text'   => wp_specialchars_decode($r->post_content, ENT_QUOTES),
                'date'   => self::human_date($r),
                'edited' => (bool) get_post_meta($r->ID, '_dcn_edited', true),
                'tagged' => array_values(array_filter((array) get_post_meta($r->ID, '_dcn_tagged', true))),
            ];
        }

        return [
            'id'       => $note->ID,
            'n'        => (int) $meta('n'),
            'author'   => (string) $meta('author'),
            'source'   => (string) $meta('source') ?: 'client',
            'group'    => self::group_for((string) $meta('group'), (string) $meta('source') ?: 'client'),
            'edited'   => (bool) $meta('edited'),
            'involved' => self::involved($note),
            'pending'  => self::is_pending((string) $meta('contact')),
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
