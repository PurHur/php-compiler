<?php
/**
 * #30027 — user/method by-ref from function return / new: Notice then continue (php-src-strict).
 * Zend: Only variables should be passed by reference; Error only for non-temp expressions.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

function f(&$a) {
    if (is_int($a)) {
        $a++;
    }
}

function g() {
    return 1;
}

f(g());
echo "ok\n";

class C {
    function m(&$a) {
        if (is_int($a)) {
            $a++;
        }
    }
}
(new C)->m(g());
echo "method-ok\n";

try {
    f($x + 1);
    echo "expr-ok\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
