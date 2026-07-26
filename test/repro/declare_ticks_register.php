<?php
declare(ticks=1);
$n = 0;
register_tick_function(function () use (&$n) { $n++; });
for ($i = 0; $i < 5; $i++) {
    $x = $i;
}
echo "n=$n\n";
// Zend: n=7
// VM (before #23486): n=1
