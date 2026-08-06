--TEST--
func_get_arg Reflection return mixed (Zend/zend_builtin_functions.stub.php, #28023)
--FILE--
<?php
foreach (['func_get_arg', 'func_get_args', 'func_num_args'] as $fn) {
    $r = new ReflectionFunction($fn);
    $ps = [];
    foreach ($r->getParameters() as $p) {
        $t = $p->hasType() ? (string) $p->getType() . ' ' : '';
        $ps[] = $t . '$' . $p->getName();
    }
    echo $fn, '(', implode(', ', $ps), ')';
    echo $r->hasReturnType() ? (': ' . (string) $r->getReturnType()) : '';
    echo "\n";
}
?>
--EXPECT--
func_get_arg(int $position): mixed
func_get_args(): array
func_num_args(): int
