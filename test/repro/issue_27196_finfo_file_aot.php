<?php

declare(strict_types=1);

/**
 * #27196 — AOT finfo::file / finfo_file MIME sniff (re-#3366).
 *
 * Expect: text/plain twice (Zend / VM / JIT / AOT).
 */
$path = sys_get_temp_dir() . '/phpc_finfo_gap_27196.txt';
file_put_contents($path, 'hello');
$f = new finfo(FILEINFO_MIME_TYPE);
echo $f->file($path), "\n";
echo finfo_file($f, $path), "\n";
