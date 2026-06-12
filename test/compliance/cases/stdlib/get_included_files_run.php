<?php
require __DIR__ . '/get_included_files_helper.inc.php';
$files = get_included_files();
$main = realpath(__FILE__);
$helper = realpath(__DIR__ . '/get_included_files_helper.inc.php');
echo in_array($main, $files, true) ? 'self' : 'missing', "\n";
echo in_array($helper, $files, true) ? 'helper' : 'missing', "\n";
echo get_included_files() === get_required_files() ? 'alias' : 'diff', "\n";
