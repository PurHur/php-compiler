--TEST--
Standalone true/false/null parameter types reject weak coercion (issue #7057, zend_execute.c)
--FILE--
<?php
function f(true $x): void { echo "ok\n"; }
function g(false $x): void { echo "ok\n"; }
function h(null $x): void { echo "ok\n"; }
f(true);
g(false);
h(null);
foreach ([['f', 1], ['g', 0], ['h', 1]] as $case) {
    try {
        $case[0]($case[1]);
    } catch (Throwable $e) {
        echo get_class($e) . "\n";
    }
}
?>
--EXPECT--
ok
ok
ok
TypeError
TypeError
TypeError
