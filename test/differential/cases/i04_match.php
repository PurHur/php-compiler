<?php
// Ordinary PHP: match(true) chain. Passes both backends.
function f($n) { return match(true) { $n < 10 => "small", $n < 100 => "mid", default => "big" }; }
echo f(5), ' ', f(50), ' ', f(500), "\n";
