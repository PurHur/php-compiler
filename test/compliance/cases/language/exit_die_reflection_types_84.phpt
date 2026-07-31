--TEST--
Language: exit/die Reflection string|int $status = 0 : never on PHP 8.4 (#26056, Zend/zend_builtin_functions.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['exit', 'die'] as $fn) {
    $r = new ReflectionFunction($fn);
    $p = $r->getParameters()[0];
    echo $fn,
        ' status=', $p->hasType() ? (string)$p->getType() : 'none',
        ' def=', $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : 'none',
        ' ret=', $r->hasReturnType() ? (string)$r->getReturnType() : 'none',
        "\n";
}
--EXPECT--
exit status=string|int def=0 ret=never
die status=string|int def=0 ret=never
