<?php
// Repro #31963 — AOT float→string must not SIGSEGV (printf/number_format/json/serialize/strval)
declare(strict_types=1);

printf("%.1f\n", 1.5);
echo number_format(1234567.891, 2), "\n";
echo json_encode(0.1), "\n";
echo serialize(0.1), "\n";
var_dump(strval(0.1));
