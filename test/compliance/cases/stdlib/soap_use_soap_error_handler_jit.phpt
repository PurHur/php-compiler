--TEST--
Stdlib: use_soap_error_handler() JIT toggle (#26168, ext/soap/soap.c)
--FILE--
<?php
declare(strict_types=1);

$a = use_soap_error_handler(false);
echo 'prev_default=', (int) $a, "\n";
$b = use_soap_error_handler(true);
echo 'prev_false=', (int) $b, "\n";
$c = use_soap_error_handler();
echo 'prev_true=', (int) $c, "\n";
$d = use_soap_error_handler(false);
echo 'prev_still_true=', (int) $d, "\n";
?>
--EXPECT--
prev_default=0
prev_false=0
prev_true=1
prev_still_true=1
