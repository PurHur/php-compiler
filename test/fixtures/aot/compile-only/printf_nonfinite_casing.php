<?php
// Compile-only (#10151): printf()/fprintf() %f — NaN/INF casing
printf("%f\n", NAN);
printf("%f %f\n", NAN, INF);
$f = fopen('php://memory', 'w+');
fprintf($f, '%f', NAN);
rewind($f);
echo stream_get_contents($f);
