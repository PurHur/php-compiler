--TEST--
Language: count() on Countable objects (VM, #3364)
--FILE--
<?php
echo interface_exists('Countable') ? '1' : '0', "\n";
class Bag implements Countable {
    public function count(): int {
        return 3;
    }
}
echo count(new Bag()), "\n";

class EmptyBag implements Countable {
    public function count(): int {
        return 0;
    }
}
echo count(new EmptyBag()), "\n";
--EXPECT--
1
3
0
