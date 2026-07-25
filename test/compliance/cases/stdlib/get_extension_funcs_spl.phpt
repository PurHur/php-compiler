--TEST--
stdlib get_extension_funcs('spl') lists 15 Zend procedurals (#23221, re-#23156)
--FILE--
<?php
$spl = get_extension_funcs('spl');
echo is_array($spl) && count($spl) === 15 ? "spl_ok\n" : "spl_bad\n";
$std = get_extension_funcs('standard');
$blob = is_array($std) ? ("\0".implode("\0", $std)."\0") : '';
echo is_array($std) && !str_contains($blob, "\0class_implements\0") ? "no_dual\n" : "dual_bad\n";
echo is_array($std) && !str_contains($blob, "\0ftp_connect\0") ? "no_ftp_dual\n" : "ftp_dual_bad\n";
echo is_array($std) && !str_contains($blob, "\0mt_rand\0") ? "no_rand_dual\n" : "rand_dual_bad\n";
--EXPECT--
spl_ok
no_dual
no_ftp_dual
no_rand_dual
