--TEST--
foreach IteratorAggregate::getIterator() non-Traversable — TypeError / Exception (zend_interfaces.c, #19729)
--FILE--
<?php
class BadTyped implements IteratorAggregate {
    public function getIterator(): Traversable {
        return 123;
    }
}
try {
    foreach (new BadTyped() as $x) {
        echo "V=$x\n";
    }
    echo "NO_THROW_TYPED\n";
} catch (TypeError $e) {
    echo "TypeError\n";
} catch (Throwable $e) {
    echo get_class($e)."\n";
}

class BadBare implements IteratorAggregate {
    public function getIterator() {
        return 123;
    }
}
try {
    foreach (new BadBare() as $x) {
        echo "V=$x\n";
    }
    echo "NO_THROW_BARE\n";
} catch (Exception $e) {
    echo (str_contains($e->getMessage(), 'must be traversable') ? "Exception_ok\n" : ("Exception_bad: ".$e->getMessage()."\n"));
} catch (Throwable $e) {
    echo get_class($e)."\n";
}
--EXPECT--
TypeError
Exception_ok
