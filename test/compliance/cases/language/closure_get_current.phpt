--TEST--
language Closure::getCurrent() — executing closure introspection (#15239, Zend/zend_closures.c)
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
function closure_get_current_outside(): mixed
{
    return Closure::getCurrent();
}
var_export(closure_get_current_outside());
echo "\n";
--EXPECT--
true
NULL
