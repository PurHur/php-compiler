<?php
// Ordinary PHP: do-while (the corpus had `while` but never `do`). Passes both backends.
$i = 0; $s = 0;
do { $s += $i; $i++; } while ($i < 5);
echo $s, "\n";
