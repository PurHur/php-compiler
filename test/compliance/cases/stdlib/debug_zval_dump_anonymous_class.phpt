--TEST--
stdlib debug_zval_dump() anonymous class — class@anonymous not internal NUL suffix (#17450, Zend/zend_builtin_functions.c)
--FILE--
<?php
declare(strict_types=1);

$o = new class {};
ob_start();
debug_zval_dump($o);
$out = ob_get_clean();
echo str_contains($out, 'object(class@anonymous)') ? "label=ok\n" : "label=fail\n";
echo str_contains($out, "\0") ? "nul=fail\n" : "nul=ok\n";
--EXPECT--
label=ok
nul=ok
