--TEST--
Property hook get body: function-local static persists across reads (#7097)
--FILE--
<?php
class Counter {
    public int $n {
        get {
            static $c = 0;
            return ++$c;
        }
    }
}

$o = new Counter();
echo $o->n, "\n";
echo $o->n, "\n";
--EXPECT--
1
2
