--TEST--
instanceof Countable — user class implementing interface (#4754)
--FILE--
<?php
class C implements Countable {
    public function count(): int { return 1; }
}
var_export(new C() instanceof Countable);
echo "\n";
?>
--EXPECT--
true
