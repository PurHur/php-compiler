--TEST--
ReflectionFunction::getClosure() — named function and fromCallable closure (#12905, ext/reflection/php_reflection.c)
--FILE--
<?php
function rf12905_answer(): int
{
    return 42;
}

$rf = new ReflectionFunction('rf12905_answer');
echo 'method_exists=', var_export(method_exists($rf, 'getClosure'), true), "\n";
$c = $rf->getClosure();
echo 'invoke=', $c(), "\n";
?>
--EXPECT--
method_exists=true
invoke=42
