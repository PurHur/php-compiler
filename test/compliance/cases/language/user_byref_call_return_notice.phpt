--TEST--
language: user/method by-ref arg from call/new return — Notice + continue (#30027)
--FILE--
<?php
error_reporting(E_ALL);

function f(&$a) {
    if (is_int($a)) {
        $a++;
    }
}

function g() {
    return 1;
}

try {
    f(g());
    echo "func: ok\n";
} catch (Throwable $e) {
    echo 'func: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    f(new stdClass);
    echo "new: ok\n";
} catch (Throwable $e) {
    echo 'new: ', get_class($e), ': ', $e->getMessage(), "\n";
}

class C {
    function m(&$a) {
        if (is_int($a)) {
            $a++;
        }
        echo "method-ran\n";
    }
}

try {
    (new C)->m(g());
    echo "method: ok\n";
} catch (Throwable $e) {
    echo 'method: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    $x = 0;
    f($x + 1);
    echo "expr: ok\n";
} catch (Throwable $e) {
    echo 'expr: ', get_class($e), ': ', $e->getMessage(), "\n";
}

$a = 1;
f($a);
echo "var: $a\n";
--EXPECTF--
PHP Notice:  Only variables should be passed by reference in %s on line %d
PHP Notice:  Only variables should be passed by reference in %s on line %d
PHP Notice:  Only variables should be passed by reference in %s on line %d
func: ok
new: ok
method-ran
method: ok
expr: Error: f(): Argument #1 ($a) could not be passed by reference
var: 2
--CREDITS--
PurHur/php-compiler issue #30027
