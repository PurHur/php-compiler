<?php
// Compile-only (#5748): stats_standard_deviation() VM registration on AOT user-script path.
$data = [1.0, 2.0, 3.0];
echo round(stats_standard_deviation($data), 3), "\n";
