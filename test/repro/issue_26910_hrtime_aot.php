<?php
// #26910 — AOT hrtime() Module verify: __compiler_hrtime_ns i64 vs double
$a = hrtime(true);
$b = hrtime(false);
echo gettype($a), ' ', count($b), "\n";
