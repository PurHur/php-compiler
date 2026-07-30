<?php
declare(strict_types=1);
// Repro #25595 — ceil/floor Reflection matches php-src math.stub.php
foreach (['ceil', 'floor'] as $f) {
    $r = new ReflectionFunction($f);
    $p = $r->getParameters()[0];
    echo $f, '|', $p->getName(), '|', (string) $p->getType(), '|ret:', (string) $r->getReturnType(), "\n";
}
echo gettype(ceil(1.2)), "\n";
echo gettype(floor(1.2)), "\n";
