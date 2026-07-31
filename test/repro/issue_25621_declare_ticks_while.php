<?php
declare(ticks=1);
$n = 0;
register_tick_function(function () use (&$n) { $n++; });
$i = 0;
while ($i < 3) { $i++; }
echo "n=$n\n";
