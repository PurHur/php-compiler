--TEST--
stdlib copy()/touch() failure returns false not NULL (#14043, ext/standard/file.c)
--FILE--
<?php

declare(strict_types=1);

$missing = '/nonexistent/phpc-copy-' . uniqid('', true);
var_export(@copy($missing, sys_get_temp_dir() . '/dst.txt'));
echo "\n";
var_export(@touch('/nonexistent_parent_' . uniqid('', true) . '/f.txt'));
echo "\n";
--EXPECT--
false
false
