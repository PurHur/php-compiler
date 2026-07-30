--TEST--
stdlib register_shutdown_function Zend stub named callback (#23380, ext/standard/basic_functions.stub.php)
--FILE--
<?php
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
--EXPECT--
params=callback,args...
num=2 req=1
main
bye
--EXPECT_EXIT--
0
