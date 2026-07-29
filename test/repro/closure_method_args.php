<?php
class Maker {
    public function getClosure(): Closure {
        return function(int $x = 1): int { return $x * 2; };
    }
    public function getArrow(): Closure {
        return fn(int $x = 1): int => $x * 3;
    }
}

$m = new Maker();

// Closure from method — explicit arg
$cl = $m->getClosure();
echo $cl(10) . "\n"; // Expected: 20

// Closure from method — default
echo $cl() . "\n";   // Expected: 2

// Arrow function from method — explicit arg
$ar = $m->getArrow();
echo $ar(5) . "\n";  // Expected: 15

// Arrow function from method — default
echo $ar() . "\n";   // Expected: 3

// Chained call
echo (new Maker)->getClosure()(7) . "\n"; // Expected: 14
echo (new Maker)->getArrow()(4) . "\n";   // Expected: 12

// Plain function closure still works
function f(): Closure { return function(int $x = 1): int { return $x; }; }
echo f()(10) . "\n"; // Expected: 10

// Top-level closure still works
$fn = function(int $x = 1): int { return $x; };
echo $fn(10) . "\n"; // Expected: 10
