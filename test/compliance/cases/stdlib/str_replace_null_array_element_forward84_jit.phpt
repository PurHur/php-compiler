--TEST--
stdlib str_replace family JIT: null array elements no parameter DEP on 8.4 (#29309)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$deps = [];
set_error_handler(function ($errno, $errstr) use (&$deps) {
    if (E_DEPRECATED === $errno || E_USER_DEPRECATED === $errno) {
        $deps[] = $errstr;
        return true;
    }
    return false;
});

$r1 = str_replace(['a'], [null], 'ab');
$r2 = str_ireplace(['A'], [null], 'Ab');
$r3 = substr_replace('abc', [null], 1, 1);
$r4 = str_replace([null], ['X'], 'a');
$param = str_replace(null, 'x', 'a');

echo 'sr=', var_export($r1, true), "\n";
echo 'si=', var_export($r2, true), "\n";
echo 'su=', var_export($r3, true), "\n";
echo 'ss=', var_export($r4, true), "\n";
echo 'param=', var_export($param, true), "\n";
echo 'deps=', count($deps), "\n";
foreach ($deps as $d) {
    echo $d, "\n";
}
?>
--EXPECT--
sr='b'
si='b'
su='ac'
ss='a'
param='a'
deps=1
str_replace(): Passing null to parameter #1 ($search) of type array|string is deprecated
