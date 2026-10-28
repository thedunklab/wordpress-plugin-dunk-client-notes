<?php
/**
 * Plugin Name: DUNK Client Notes
 * Description: Note e commenti del cliente direttamente sulle pagine dello staging: puntine sull'elemento, screenshot automatico, risposte e stato. Da tenere attivo solo in staging.
 * Version:     1.2.0
 * Author:      DUNK Agency
 * Author URI:  https://thedunk.it
 * Text Domain: dunk-client-notes
 * Requires PHP: 8.0
 * License:     GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Dunk\ClientNotes;

if (!defined('ABSPATH')) {
    exit;
}

define('DCN_VERSION', '1.2.0');
define('DCN_FILE', __FILE__);
define('DCN_DIR', plugin_dir_path(__FILE__));
define('DCN_URL', plugin_dir_url(__FILE__));

require_once DCN_DIR . 'includes/Plugin.php';
require_once DCN_DIR . 'includes/Rest.php';
require_once DCN_DIR . 'includes/Mail.php';
require_once DCN_DIR . 'includes/Admin.php';

Plugin::init();
Mail::init();
Admin::init();
