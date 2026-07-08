--TEST--
stdlib var_dump()/print_r() anonymous class — class@anonymous not internal NUL suffix (#17444, ext/standard/var.c)
--FILE--
<?php
declare(strict_types=1);

$o = new class {};
ob_start();
var_dump($o);
$dump = ob_get_clean();
echo str_contains($dump, 'object(class@anonymous)') ? "var_dump_label=ok\n" : "var_dump_label=fail\n";
echo str_contains($dump, "\0") ? "var_dump_nul=fail\n" : "var_dump_nul=ok\n";

ob_start();
print_r($o);
$pr = ob_get_clean();
echo str_contains($pr, 'class@anonymous Object') ? "print_r_label=ok\n" : "print_r_label=fail\n";
echo str_contains($pr, "\0") ? "print_r_nul=fail\n" : "print_r_nul=ok\n";
--EXPECT--
var_dump_label=ok
var_dump_nul=ok
print_r_label=ok
print_r_nul=ok
