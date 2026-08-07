<?php
/**
 * Repro for #26744 — uploadprogress phantom when host Zend lacks pecl-uploadprogress.
 *
 * Zend: extension_loaded + both functions false.
 * Default VM (before fix): all true.
 *
 * Run:
 *   php test/repro/maintainer_gap_uploadprogress_phantom.php
 *   php bin/vm.php test/repro/maintainer_gap_uploadprogress_phantom.php
 */
echo 'ext=', extension_loaded('uploadprogress') ? '1' : '0', "\n";
echo 'info=', function_exists('uploadprogress_get_info') ? '1' : '0', "\n";
echo 'contents=', function_exists('uploadprogress_get_contents') ? '1' : '0', "\n";
