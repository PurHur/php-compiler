<?php
// Issue #27314 — AOT ReflectionEnum getName/isBacked/getCases (php-src-strict).
// Enum case list length via foreach (count() also OK under thin AOT after #26957).
enum E: int { case A = 1; case B = 2; }
$r = new ReflectionEnum(E::class);
$n = 0;
foreach ($r->getCases() as $_) {
    $n++;
}
echo $r->getName(), '|', ($r->isBacked() ? 'y' : 'n'), '|', $n, "\n";
