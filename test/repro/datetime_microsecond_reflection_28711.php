<?php
/**
 * #28711 — DateTime(Immutable) microsecond / createFromTimestamp Reflection returns (PROFILE≥8.4).
 */
$methods = [
    ['DateTimeImmutable', 'createFromTimestamp'],
    ['DateTimeImmutable', 'getMicrosecond'],
    ['DateTimeImmutable', 'setMicrosecond'],
    ['DateTime', 'createFromTimestamp'],
    ['DateTime', 'getMicrosecond'],
    ['DateTime', 'setMicrosecond'],
];
foreach ($methods as [$c, $m]) {
    $r = new ReflectionMethod($c, $m);
    echo "$c::$m ret=", $r->hasReturnType() ? (string) $r->getReturnType() : 'none';
    if (method_exists($r, 'hasTentativeReturnType') && $r->hasTentativeReturnType()) {
        echo ' tentative=', (string) $r->getTentativeReturnType();
    }
    echo PHP_EOL;
}
$d = DateTimeImmutable::createFromTimestamp(1.5);
$d2 = $d->setMicrosecond(123456);
echo 'usec=', $d2->getMicrosecond(), PHP_EOL;
