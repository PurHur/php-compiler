--TEST--
Language: asymmetric visibility forward 8.4 profile — public private(set) / public protected(set) (#16996, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

class PrivateSet {
    public private(set) int $x = 1;
}
$c = new PrivateSet();
echo $c->x, "\n";
try {
    $c->x = 2;
    echo "private write ok\n";
} catch (Error $e) {
    echo 'private write ', get_class($e), "\n";
}

class ProtectedSet {
    public protected(set) string $label = 'hi';
}
$p = new ProtectedSet();
echo $p->label, "\n";
try {
    $p->label = 'z';
    echo "protected write ok\n";
} catch (Error $e) {
    echo 'protected write ', get_class($e), "\n";
}
--EXPECT--
1
private write Error
hi
protected write Error
