<?php
declare(strict_types=1);

/**
 * Issue #28710 — Closure::getCurrent() under PHP_COMPILER_PROFILE=8.5
 * (php-src Zend/zend_closures.stub.php; method is 8.5+ only, not 8.4).
 *
 * Run:
 *   PHP_COMPILER_PROFILE=8.5 php bin/vm.php test/repro-maintainer/parity_closure_getcurrent.php
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro-maintainer/parity_closure_getcurrent.php
 */
$exists = method_exists(Closure::class, 'getCurrent');
echo 'method_exists=', $exists ? 'Y' : 'N', "\n";
if (!$exists) {
    exit(0);
}
$seen = null;
$f = function () use (&$seen) {
    $seen = Closure::getCurrent();
    return 1;
};
$f();
echo 'is_closure=', $seen instanceof Closure ? 'Y' : 'N', "\n";
$r = new ReflectionMethod(Closure::class, 'getCurrent');
echo 'static=', $r->isStatic() ? 'Y' : 'N', "\n";
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
