<?php

/**
 * #28533 — ReflectionProperty::isReadable/isWritable are PHP 8.5+ only.
 * php-src: ext/reflection/php_reflection.stub.php (absent on PHP-8.4).
 *
 * Run with PROFILE=8.4 and PROFILE=8.5 (see issue Done when).
 */

$profile = getenv('PHP_COMPILER_PROFILE') ?: '(default)';
$exists = method_exists(ReflectionProperty::class, 'isReadable');

if (version_compare((string) (getenv('PHP_COMPILER_PROFILE') ?: '0'), '8.5', '>=')) {
    if (!$exists) {
        echo "fail: missing on PROFILE={$profile}\n";
        exit(1);
    }
    $m = new ReflectionMethod(ReflectionProperty::class, 'isReadable');
    if (2 !== $m->getNumberOfParameters() || 1 !== $m->getNumberOfRequiredParameters()) {
        echo 'fail: arity=', $m->getNumberOfParameters(), ' req=', $m->getNumberOfRequiredParameters(), "\n";
        exit(1);
    }
    $names = [];
    foreach ($m->getParameters() as $p) {
        $names[] = $p->getName().':'.(string) $p->getType();
    }
    if (['scope:?string', 'object:?object'] !== $names) {
        echo 'fail: params=', implode(',', $names), "\n";
        exit(1);
    }
    class Issue28533Probe
    {
        public int $x = 1;
    }
    $r = new ReflectionProperty(Issue28533Probe::class, 'x');
    if (!$r->isReadable(null) || !$r->isWritable(scope: null)) {
        echo "fail: probe false\n";
        exit(1);
    }
    try {
        $r->isReadable();
        echo "fail: zero-arg ok\n";
        exit(1);
    } catch (ArgumentCountError $e) {
        // expected
    }
    echo "ok\n";
    exit(0);
}

if ($exists) {
    echo "phantom:ReflectionProperty::isReadable on PROFILE={$profile}\n";
    exit(1);
}
if (method_exists(ReflectionProperty::class, 'isWritable')) {
    echo "phantom:ReflectionProperty::isWritable on PROFILE={$profile}\n";
    exit(1);
}
echo "ok\n";
