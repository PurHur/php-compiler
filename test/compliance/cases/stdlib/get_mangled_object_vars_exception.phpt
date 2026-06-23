--TEST--
Stdlib: get_mangled_object_vars() on Exception — mangled internal keys (#10720, ext/standard/var.c)
--FILE--
<?php
declare(strict_types=1);
$e = new Exception('msg', 42);
$vars = get_mangled_object_vars($e);
ksort($vars);
foreach (array_keys($vars) as $k) {
    echo json_encode($k), "\n";
}
echo isset($vars["\0*\0message"]) ? '1' : '0';
?>
--EXPECT--
"\u0000*\u0000code"
"\u0000*\u0000file"
"\u0000*\u0000line"
"\u0000*\u0000message"
"\u0000Exception\u0000previous"
"\u0000Exception\u0000string"
"\u0000Exception\u0000trace"
1
