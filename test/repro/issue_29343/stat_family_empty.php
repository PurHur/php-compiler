<?php
set_error_handler(function ($errno, $errstr) {
    fwrite(STDERR, "WARN[$errno]: $errstr\n");
    return true;
});
foreach ([
    'filesize', 'filemtime', 'fileatime', 'filectime',
    'fileowner', 'filegroup', 'fileinode', 'fileperms',
    'filetype', 'stat', 'lstat',
] as $f) {
    $r = $f('');
    echo $f, '=', var_export($r, true), "\n";
}
