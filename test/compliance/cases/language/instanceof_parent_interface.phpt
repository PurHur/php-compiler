--TEST--
instanceof parent interface — Generator and IteratorAggregate satisfy Traversable (#4771)
--FILE--
<?php
function gen(): Generator {
    yield 1;
}
$gen = gen();
var_export($gen instanceof Traversable);
echo "\n";
var_export($gen instanceof Iterator);
echo "\n";
var_export(is_a($gen, Traversable::class));
echo "\n";

class Agg implements IteratorAggregate {
    public function getIterator(): Traversable {
        yield 1;
    }
}
$a = new Agg();
var_export($a instanceof Traversable);
echo "\n";
var_export($a instanceof IteratorAggregate);
echo "\n";
var_export(is_a($a, Traversable::class));
echo "\n";
?>
--EXPECT--
true
true
true
true
true
true

--JIT--
