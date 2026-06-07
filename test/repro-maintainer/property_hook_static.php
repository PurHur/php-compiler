<?php
// Maintainer repro for #7097 — static variables in property hook bodies must persist.
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
