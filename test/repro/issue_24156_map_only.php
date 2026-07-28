<?php
/** #24156 array_map-only */
$r = array_map(fn($x) => $x * 2, [1, 2, 3]);
echo implode(',', $r), "\n";
