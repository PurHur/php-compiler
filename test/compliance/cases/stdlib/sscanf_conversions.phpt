--TEST--
stdlib sscanf() — extended conversion specifiers %x/%o/%u/%c (#4158)
--FILE--
<?php
$r = sscanf('ff', '%x');
echo isset($r[0]) ? (string) $r[0] : 'null', "\n";

$r = sscanf('377', '%o');
echo isset($r[0]) ? (string) $r[0] : 'null', "\n";

$r = sscanf('42', '%u');
echo isset($r[0]) ? (string) $r[0] : 'null', "\n";

$r = sscanf('-42', '%u');
echo isset($r[0]) ? $r[0] : 'null', "\n";

$r = sscanf('A', '%c');
echo isset($r[0]) ? $r[0] : 'null', "\n";

try {
    sscanf('x', '%b');
    echo "no_error\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
255
255
42
18446744073709551574
A
Bad scan conversion character "b"
