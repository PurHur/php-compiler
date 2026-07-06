--TEST--
Language: asymmetric visibility forward 8.4 profile compliance (#16996, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

class PrivateSet {
    public private(set) int $x = 1;
}

class ProtectedSet {
    public protected(set) string $label = 'ok';
}

$c = new PrivateSet();
echo $c->x, "\n";
try {
    $c->x = 2;
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

$p = new ProtectedSet();
echo $p->label, "\n";
try {
    $p->label = 'no';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
1
Error: Cannot modify public private(set) property PrivateSet::$x from global scope
ok
Error: Cannot modify public protected(set) property ProtectedSet::$label from global scope
