<?php
// Ordinary PHP: switch with returns and a default. Passes both backends.
function f($n) { switch ($n) { case 1: return "one"; case 2: return "two"; default: return "many"; } }
echo f(1), ' ', f(2), ' ', f(9), "\n";
