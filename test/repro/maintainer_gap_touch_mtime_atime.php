<?php
/**
 * Repro #12742 — touch() mtime/atime must update on-disk times (php-src ext/standard/filestat.c).
 */
$f = tempnam(sys_get_temp_dir(), 't');
touch($f, 1000, 900);
var_export(filemtime($f) === 1000 && fileatime($f) === 900);
@unlink($f);
