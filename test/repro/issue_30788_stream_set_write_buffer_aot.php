<?php

declare(strict_types=1);

/**
 * Repro #30788 — stream_set_write_buffer / set_file_buffer AOT must return -1 like Zend.
 *
 * php-src: ext/standard/streamsfuncs.c — RETURN_LONG(ret == 0 ? 0 : EOF)
 */
$path = sys_get_temp_dir().'/phpc_issue_30788_sswb.txt';
$f = fopen($path, 'w');
echo 'write=', stream_set_write_buffer($f, 0), "\n";
echo 'alias=', set_file_buffer($f, 0), "\n";
echo 'read=', stream_set_read_buffer($f, 0), "\n";
fclose($f);
@unlink($path);
