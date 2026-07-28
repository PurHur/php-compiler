--TEST--
CREDITS_ALL / INFO_ALL are uint32 max 4294967295 (php-src-strict, issue #24135)
--FILE--
<?php
declare(strict_types=1);
echo 'CREDITS_ALL=', var_export(CREDITS_ALL, true), "\n";
echo 'INFO_ALL=', var_export(INFO_ALL, true), "\n";
echo 'credits_eq=', var_export(CREDITS_ALL === 4294967295, true), "\n";
echo 'info_eq=', var_export(INFO_ALL === 4294967295, true), "\n";
echo 'credits_neg=', var_export(CREDITS_ALL === -1, true), "\n";
echo 'info_neg=', var_export(INFO_ALL === -1, true), "\n";
ob_start();
phpcredits(CREDITS_ALL);
$c = ob_get_clean();
ob_start();
phpinfo(INFO_ALL);
$i = ob_get_clean();
echo 'credits_len=', (strlen($c) > 1000 ? 'ok' : 'short'), "\n";
echo 'info_len=', (strlen($i) > 100 ? 'ok' : 'short'), "\n";
?>
--EXPECT--
CREDITS_ALL=4294967295
INFO_ALL=4294967295
credits_eq=true
info_eq=true
credits_neg=false
info_neg=false
credits_len=ok
info_len=ok
