<?php
/**
 * Issue #23380 — register_shutdown_function Reflection names + named callback:.
 * php-src: ext/standard/basic_functions.stub.php
 */
$rf = new ReflectionFunction('register_shutdown_function');
$bits = [];
foreach ($rf->getParameters() as $p) {
    $bits[] = $p->getName().($p->isVariadic() ? '...' : '');
}
echo 'params=', implode(',', $bits), "\n";
echo 'num=', $rf->getNumberOfParameters(), ' req=', $rf->getNumberOfRequiredParameters(), "\n";

register_shutdown_function(callback: static function (): void {
    echo "bye\n";
});
echo "main\n";
?>
