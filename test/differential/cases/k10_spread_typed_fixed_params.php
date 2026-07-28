<?php
// AOT: spread into fixed TYPED params — compile + run (#24167).
function s3(int $a, int $b, int $c): int {
    return $a + $b + $c;
}
$p = [1, 2, 3];
echo s3(...$p), "\n";
