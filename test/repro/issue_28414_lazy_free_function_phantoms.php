<?php

declare(strict_types=1);

/**
 * #28414 — free-function createLazy* phantoms must stay off under PROFILE≥8.4.
 * php-src exposes ReflectionClass::newLazyGhost / newLazyProxy only.
 */
foreach (['createLazyGhost', 'createLazyProxy', 'createlazyghost', 'createlazyproxy'] as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', PHP_EOL;
}
echo method_exists(ReflectionClass::class, 'newLazyGhost') ? "RC_ok\n" : "RC_bad\n";
echo method_exists(ReflectionClass::class, 'newLazyProxy') ? "RC_proxy_ok\n" : "RC_proxy_bad\n";
