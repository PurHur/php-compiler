<?php

/** #24156 debug — single element. */
echo array_reduce([1], fn($c, $x) => $c + $x, 0), "\n";
