<?php

declare(strict_types=1);

class ReflectionReadablePhantomProbe {
    public int $x = 1;
}

$r = new ReflectionProperty(ReflectionReadablePhantomProbe::class, 'x');
foreach (['isReadable', 'isWritable'] as $method) {
    if (method_exists($r, $method)) {
        file_put_contents('php://stderr', "unexpected PHP 8.4 method on reference profile: {$method}\n");
        exit(1);
    }
}
echo "ok\n";
