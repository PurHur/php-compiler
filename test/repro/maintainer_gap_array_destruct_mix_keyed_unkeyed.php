<?php

// Maintainer gap #14879 — short [] destructuring mix keyed/unkeyed.
[0 => $x, $y] = [1, 2];
echo $x . $y;
