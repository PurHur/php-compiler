--TEST--
AOT: native int param from boxed __value__ (Native::compileArg, issue #816)
--GET--
n=3
--FILE--
<?php
function repeatChar(string $s, int $n): string {
    $out = '';
    for ($i = 0; $i < $n; $i++) {
        $out .= $s;
    }
    return $out;
}
$n = $_GET['n'];
echo repeatChar('a', $n);
--EXPECT--
aaa
