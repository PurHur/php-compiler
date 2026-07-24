<?php
$n = 0;
register_tick_function(function () use (&$n) { $n++; });
declare(ticks=1) {
  $a = 1;
  $b = 2;
  $c = 3;
}
echo "n=$n\n";
$n = 0;
declare(ticks=2) {
  $a = 1; $b = 2; $c = 3; $d = 4;
}
echo "n2=$n\n";
