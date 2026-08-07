<?php

declare(strict_types=1);

/**
 * #27707 — spl_object_id Reflection object→int (php-src ext/spl/spl.stub.php).
 */

$r = new ReflectionFunction('spl_object_id');
$p = $r->getParameters()[0];
if ('object' !== $p->getName()) {
    echo 'fail: name='.$p->getName()."\n";
    exit(1);
}
if ('object' !== (string) $p->getType()) {
    echo 'fail: type='.(string) $p->getType()."\n";
    exit(1);
}
if ('int' !== (string) $r->getReturnType()) {
    echo 'fail: return='.(string) $r->getReturnType()."\n";
    exit(1);
}

$o = new stdClass();
$id = spl_object_id($o);
if (spl_object_id(object: $o) !== $id) {
    echo "fail: named object: mismatch\n";
    exit(1);
}

try {
    spl_object_id(obj: $o);
    echo "fail: wrong named arg accepted\n";
    exit(1);
} catch (Error $e) {
    if (!str_contains($e->getMessage(), 'Unknown named parameter $obj')) {
        echo 'fail: '.$e->getMessage()."\n";
        exit(1);
    }
}

try {
    spl_object_id(1);
    echo "fail: int accepted\n";
    exit(1);
} catch (TypeError $e) {
    // ok
}

echo "ok\n";
