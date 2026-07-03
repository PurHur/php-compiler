<?php

declare(strict_types=1);

$d = new DOMDocument();
$d->loadHTML('<p>hi</p>');
$path = sys_get_temp_dir().'/dom_savehtmlfile_'.getmypid().'.html';
$bytes = $d->saveHTMLFile($path);
if (!is_int($bytes) || $bytes <= 0) {
    echo 'fail: byte count ', var_export($bytes, true), "\n";
    @unlink($path);
    exit(1);
}
$contents = file_get_contents($path);
if (!is_string($contents) || strlen($contents) !== $bytes) {
    echo 'fail: file length ', is_string($contents) ? strlen($contents) : 'null', ' vs ', $bytes, "\n";
    @unlink($path);
    exit(1);
}
if (!str_contains($contents, '<p>hi</p>')) {
    echo 'fail: missing paragraph in output', "\n";
    @unlink($path);
    exit(1);
}
@unlink($path);
echo "ok\n";
