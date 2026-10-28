<?php

declare(strict_types=1);

namespace Dunk\ClientNotes;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Aggiornamenti dalla repo GitHub (privata) direttamente dalla bacheca di WordPress:
 * l'ultimo tag semver è la versione disponibile, il suo CHANGELOG.md è il registro delle novità.
 */
final class Updater
{
    private const REPO = 'thedunklab/wordpress-plugin-dunk-client-notes';
    private const CACHE = 'dcn_update_info';

    public static function init(): void
    {
        if (self::dev()) {
            return; // sottomodulo di sviluppo: la versione la decide git, non WordPress
        }
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'inject']);
        add_filter('plugins_api', [self::class, 'details'], 20, 3);
        add_filter('http_request_args', [self::class, 'auth'], 10, 2);
        add_filter('upgrader_source_selection', [self::class, 'rename'], 10, 4);
    }

    /** Dentro un checkout git (sottomodulo) gli aggiornamenti da bacheca sono spenti. */
    public static function dev(): bool
    {
        return file_exists(DCN_DIR . '.git');
    }

    public static function token(): string
    {
        return defined('DUNK_NOTES_GITHUB_TOKEN') ? (string) DUNK_NOTES_GITHUB_TOKEN : (string) get_option('dcn_github_token', '');
    }

    private static function get(string $path, string $accept = 'application/vnd.github+json'): array
    {
        $headers = ['Accept' => $accept, 'User-Agent' => 'dunk-client-notes'];
        if (self::token() !== '') {
            $headers['Authorization'] = 'Bearer ' . self::token();
        }
        $res = wp_remote_get('https://api.github.com' . $path, ['headers' => $headers, 'timeout' => 12]);
        if (is_wp_error($res)) {
            return [0, $res->get_error_message()];
        }
        return [(int) wp_remote_retrieve_response_code($res), (string) wp_remote_retrieve_body($res)];
    }

    /** @return array{version:string,zip:string,changelog:string,checked:int,error:string} */
    public static function latest(bool $force = false): array
    {
        $cached = get_transient(self::CACHE);
        if (!$force && is_array($cached)) {
            return $cached;
        }
        $info = ['version' => '', 'zip' => '', 'changelog' => '', 'checked' => time(), 'error' => ''];
        [$code, $body] = self::get('/repos/' . self::REPO . '/tags?per_page=50');
        if ($code !== 200) {
            $info['error'] = match (true) {
                $code === 0 => 'Connessione a GitHub non riuscita: ' . $body,
                in_array($code, [401, 403, 404], true) => 'GitHub non ha accettato il token (codice ' . $code . '): ' . (self::token() === '' ? 'nessun token impostato.' : 'controlla che sia valido e abbia accesso in lettura alla repo.'),
                default => 'Risposta inattesa da GitHub (codice ' . $code . ').',
            };
            set_transient(self::CACHE, $info, 15 * MINUTE_IN_SECONDS);
            return $info;
        }
        $best = null;
        foreach ((array) json_decode($body, true) as $tag) {
            $v = ltrim((string) ($tag['name'] ?? ''), 'vV');
            if (preg_match('/^\d+\.\d+\.\d+$/', $v) && (!$best || version_compare($v, $best['version'], '>'))) {
                $best = ['version' => $v, 'zip' => (string) ($tag['zipball_url'] ?? ''), 'tag' => (string) $tag['name']];
            }
        }
        if ($best) {
            $info['version'] = $best['version'];
            $info['zip'] = $best['zip'];
            [$c, $md] = self::get('/repos/' . self::REPO . '/contents/CHANGELOG.md?ref=' . rawurlencode($best['tag']), 'application/vnd.github.raw');
            if ($c === 200 && str_starts_with(ltrim($md), '{')) { // risposta JSON invece del testo: si decodifica il contenuto
                $j = json_decode($md, true);
                $md = is_array($j) && ($j['encoding'] ?? '') === 'base64' ? (string) base64_decode((string) $j['content']) : '';
            }
            $info['changelog'] = $c === 200 ? $md : '';
        } else {
            $info['error'] = 'Nessuna versione (tag vX.Y.Z) trovata nella repo.';
        }
        set_transient(self::CACHE, $info, 12 * HOUR_IN_SECONDS);
        return $info;
    }

    public static function newer(?array $info = null): bool
    {
        $info ??= self::latest();
        return $info['version'] !== '' && version_compare($info['version'], DCN_VERSION, '>');
    }

    /** Aggiunge il plugin all'elenco degli aggiornamenti di WordPress (vale anche per gli aggiornamenti automatici). */
    public static function inject($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }
        $base = plugin_basename(DCN_FILE);
        $info = self::latest();
        $item = (object) [
            'id' => 'dunk-client-notes',
            'slug' => dirname($base),
            'plugin' => $base,
            'new_version' => $info['version'] ?: DCN_VERSION,
            'url' => 'https://thedunk.it',
            'package' => $info['zip'],
            'icons' => [],
            'requires_php' => '8.0',
        ];
        if (self::newer($info) && $info['zip'] !== '') {
            $transient->response[$base] = $item;
        } else {
            $item->package = '';
            $transient->no_update[$base] = $item; // permette il pulsante «attiva aggiornamenti automatici»
        }
        return $transient;
    }

    /** Scheda «Visualizza dettagli» con la tab Changelog. */
    public static function details($res, $action, $args)
    {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== dirname(plugin_basename(DCN_FILE))) {
            return $res;
        }
        $info = self::latest();
        $md = self::newer($info) && $info['changelog'] !== '' ? $info['changelog'] : (string) @file_get_contents(DCN_DIR . 'CHANGELOG.md');
        return (object) [
            'name' => 'DUNK Client Notes',
            'slug' => dirname(plugin_basename(DCN_FILE)),
            'version' => self::newer($info) ? $info['version'] : DCN_VERSION,
            'author' => '<a href="https://thedunk.it">DUNK Agency</a>',
            'homepage' => 'https://thedunk.it',
            'requires_php' => '8.0',
            'download_link' => self::newer($info) ? $info['zip'] : '',
            'sections' => [
                'description' => '<p>Note e commenti del cliente direttamente sulle pagine dello staging: puntine sull’elemento, screenshot automatico, risposte e stato.</p>',
                'changelog' => self::markdown($md),
            ],
        ];
    }

    /** Il download dalla repo privata richiede il token. */
    public static function auth(array $args, string $url): array
    {
        if (self::token() !== '' && str_starts_with($url, 'https://api.github.com/repos/' . self::REPO . '/zipball/')) {
            $args['headers']['Authorization'] = 'Bearer ' . self::token();
            $args['headers']['Accept'] = 'application/vnd.github+json';
        }
        return $args;
    }

    /** Lo zip di GitHub contiene una cartella «owner-repo-hash»: la rinomina in quella del plugin. */
    public static function rename($source, $remote, $upgrader, $extra)
    {
        $base = plugin_basename(DCN_FILE);
        if (($extra['plugin'] ?? '') !== $base) {
            return $source;
        }
        global $wp_filesystem;
        $dest = trailingslashit(dirname(untrailingslashit((string) $source))) . dirname($base) . '/';
        if ($dest === $source) {
            return $source;
        }
        return $wp_filesystem->move($source, $dest, true) ? $dest : new \WP_Error('dcn_rename', 'Impossibile rinominare la cartella del plugin aggiornato.');
    }

    /** Markdown minimo del changelog (titoli «## », elenchi «- », `codice`, **grassetto**) in HTML sicuro. */
    public static function markdown(string $md): string
    {
        $inline = static fn (string $t): string => preg_replace(['/`([^`]+)`/', '/\*\*([^*]+)\*\*/'], ['<code>$1</code>', '<strong>$1</strong>'], esc_html($t));
        $out = '';
        $list = false;
        foreach (preg_split('/\R/', $md) as $line) {
            $line = rtrim($line);
            $item = str_starts_with($line, '- ');
            if ($list && !$item) {
                $out .= '</ul>';
                $list = false;
            }
            if ($item) {
                $out .= ($list ? '' : '<ul>') . '<li>' . $inline(substr($line, 2)) . '</li>';
                $list = true;
            } elseif (str_starts_with($line, '## ')) {
                $out .= '<h4>' . $inline(substr($line, 3)) . '</h4>';
            } elseif ($line !== '' && !str_starts_with($line, '# ')) {
                $out .= '<p>' . $inline($line) . '</p>';
            }
        }
        return $out . ($list ? '</ul>' : '');
    }

    /** Forza un nuovo controllo (pulsante «Controlla ora»). */
    public static function refresh(): void
    {
        delete_transient(self::CACHE);
        self::latest(true);
        delete_site_transient('update_plugins');
        wp_update_plugins();
    }
}
