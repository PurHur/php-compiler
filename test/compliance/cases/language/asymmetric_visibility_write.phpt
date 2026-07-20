--TEST--
PHP 8.4 asymmetric visibility: write scope Error parity (#5635, zend_object_handlers.c)
--FILE--
<?php
class PrivateSet {
    public (private(set)) string $name = 'x';

    public function mutate(): void {
        $this->name = 'y';
    }
}

class ProtectedSet {
    public (protected(set)) string $tag = 'a';

    public function mutate(): void {
        $this->tag = 'b';
    }
}

$p = new PrivateSet();
try {
    $p->name = 'bad';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo $p->name, "\n";
$p->mutate();
echo $p->name, "\n";

$q = new ProtectedSet();
try {
    $q->tag = 'bad';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo $q->tag, "\n";
$q->mutate();
echo $q->tag, "\n";
--EXPECT--
Error: Cannot modify private(set) property PrivateSet::$name from global scope
x
y
Error: Cannot modify protected(set) property ProtectedSet::$tag from global scope
a
b
