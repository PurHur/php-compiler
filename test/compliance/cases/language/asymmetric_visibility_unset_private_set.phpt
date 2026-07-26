--TEST--
Language: unset() respects asymmetric set visibility (#23338, zend_object_handlers.c)
--FILE--
<?php
class PrivateSet {
    public private(set) ?string $name = 'a';

    public function clear(): void {
        unset($this->name);
        echo "private_in_class\n";
    }
}

class ProtectedSet {
    public protected(set) ?string $tag = 't';

    public function clear(): void {
        unset($this->tag);
        echo "protected_in_class\n";
    }
}

class ProtectedChild extends ProtectedSet {
    public function clearFromChild(): void {
        unset($this->tag);
        echo "protected_child\n";
    }
}

$p = new PrivateSet();
try {
    unset($p->name);
    echo "private_global_ok\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$p->clear();

$q = new ProtectedSet();
try {
    unset($q->tag);
    echo "protected_global_ok\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$q->clear();
(new ProtectedChild())->clearFromChild();
--EXPECT--
Error: Cannot unset private(set) property PrivateSet::$name from global scope
private_in_class
Error: Cannot unset protected(set) property ProtectedSet::$tag from global scope
protected_in_class
protected_child
