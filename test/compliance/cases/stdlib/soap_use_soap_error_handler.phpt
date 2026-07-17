--TEST--
stdlib use_soap_error_handler enable/disable round-trip (#20267, ext/soap/soap.c)
--FILE--
<?php
declare(strict_types=1);

echo 'exists=', (int) function_exists('use_soap_error_handler'), "\n";
echo 'loaded=', (int) extension_loaded('soap'), "\n";

// php-src MINIT/RINIT default: false; returns previous then sets
$a = use_soap_error_handler(false);
echo 'prev_default=', (int) $a, "\n";
$b = use_soap_error_handler(true);
echo 'prev_false=', (int) $b, "\n";
$c = use_soap_error_handler();
echo 'prev_true=', (int) $c, "\n";
$d = use_soap_error_handler(false);
echo 'prev_still_true=', (int) $d, "\n";
echo "done\n";
--EXPECT--
exists=1
loaded=1
prev_default=0
prev_false=0
prev_true=1
prev_still_true=1
done
