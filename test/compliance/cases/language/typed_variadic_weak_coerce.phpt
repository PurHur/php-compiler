--TEST--
typed variadic weak-mode element coercion (Zend zend_execute.c, #26587)
--FILE--
<?php
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    $seen[] = [$no, $str];
    return true;
});

function f(int ...$xs) {
    $types = [];
    foreach ($xs as $v) {
        $types[] = gettype($v);
    }
    echo implode(',', $types), "\n";
}

function g(string ...$xs) {
    $out = [];
    foreach ($xs as $v) {
        $out[] = gettype($v) . ':' . $v;
    }
    echo implode(',', $out), "\n";
}

f(1, '2', 3.0);

$a = '2';
f(1, $a);
echo 'caller:', gettype($a), "\n";

g(1, 2);

$seen = [];
f(1.5);
echo 'lossy_depr=', (isset($seen[0]) && E_DEPRECATED === $seen[0][0]) ? '1' : '0', "\n";
echo 'lossy_msg=', $seen[0][1] ?? '', "\n";

try {
    eval('declare(strict_types=1); function fs(int ...$xs) { return $xs; } fs(1, "2");');
    echo "strict_ok\n";
} catch (TypeError $e) {
    echo 'strict_te=1', "\n";
}
--EXPECT--
integer,integer,integer
integer,integer
caller:string
string:1,string:2
integer
lossy_depr=1
lossy_msg=Implicit conversion from float 1.5 to int loses precision
strict_te=1
