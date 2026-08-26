<?php

/**
 * AOT: ZipArchive stub props after new — no SIGSEGV (#35002 leftover of #20584).
 *
 * Run: PHP_COMPILER_ENABLE_ZIP=1 ./script/docker-exec.sh -- bash -lc \
 *   'source script/php-env.sh; php bin/compile.php -o /tmp/zp.bin test/repro/aot_ziparchive_ctor_props_35002.php && /tmp/zp.bin'
 */
$z = new ZipArchive();
echo 'status=';
var_export($z->status);
echo ' statusSys=';
var_export($z->statusSys);
echo ' lastId=';
var_export($z->lastId);
echo ' filename=';
var_export($z->filename);
echo ' numFiles=';
var_export($z->numFiles);
echo ' comment=';
var_export($z->comment);
echo "\n";
