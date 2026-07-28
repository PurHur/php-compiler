<?php

/** #24156 debug — empty array (no closure invoke). */
echo array_reduce([], fn($c, $x) => $c + $x, 0), "\n";
