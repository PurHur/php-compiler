--TEST--
Closure::getCurrent() — executing closure introspection (#13981, Zend/zend_closures.c)
--FILE--
<?php
declare(strict_types=1);
$seen = false;
$c = function (int $p) use (&$seen) {
    $self = Closure::getCurrent();
    $seen = $self instanceof Closure;
    if ($p < 2) {
        $self($p + 1);
    }
};
$c(0);
var_export($seen);
echo "\n";
function closure_get_current_outside(): void
{
    Closure::getCurrent();
}
try {
    closure_get_current_outside();
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
true
Current function is not a closure
