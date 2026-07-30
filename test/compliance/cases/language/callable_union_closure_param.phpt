--TEST--
callable|string / callable|array accept Closure (FCC + anonymous) (#25561, Zend/zend_execute.c)
--FILE--
<?php
declare(strict_types=1);

function double25561(int $x): int {
    return $x * 2;
}

function f_callable_string(callable|string $c): string {
    if (is_string($c)) {
        return 'str';
    }
    return 'fn:' . $c(2);
}

function f_string_callable(string|callable $c): string {
    if (is_string($c)) {
        return 'str';
    }
    return 'fn:' . $c(2);
}

class C25561 {
    public static function m(int $x): int {
        return $x * 3;
    }
}

function f_callable_array(callable|array $c): string {
    if (is_array($c)) {
        return 'arr';
    }
    return 'fn:' . $c(2);
}

echo f_callable_string('double25561'), "\n";
echo f_callable_string(double25561(...)), "\n";
echo f_callable_string(function (int $x): int { return $x; }), "\n";
echo f_string_callable(double25561(...)), "\n";
echo f_callable_array(C25561::m(...)), "\n";
echo f_callable_array([1, 2]), "\n";

try {
    f_callable_string(1);
    echo "no error\n";
} catch (TypeError $e) {
    echo 'reject-int:', (str_contains($e->getMessage(), 'callable|string') ? 'yes' : 'no'), "\n";
}
?>
--EXPECT--
str
fn:4
fn:2
fn:4
fn:6
arr
reject-int:yes
