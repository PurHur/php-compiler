<?php

declare(strict_types=1);

/**
 * On 8.4 forward profile isReadable/isWritable are registered (#13663).
 * Zend 8.2 reference profile omits them — use compliance skip or Zend compare for phantom checks.
 */
class ReflectionReadablePhantomProbe {
    public int $x = 1;
}

$r = new ReflectionProperty(ReflectionReadablePhantomProbe::class, 'x');
foreach (['isReadable', 'isWritable'] as $method) {
    if (!method_exists($r, $method)) {
        echo "fail: expected {$method} on 8.4 forward profile\n";
        exit(1);
    }
}
echo "ok\n";
