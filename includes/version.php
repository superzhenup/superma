<?php
/**
 * Public product release version.
 *
 * Keep this separate from database schema versions and historical migration
 * comments. Product-facing UI and telemetry should read this constant.
 */

defined('APP_LOADED') or die('Direct access denied.');

if (!defined('APP_VERSION')) {
    define('APP_VERSION', '1.8.0');
}
