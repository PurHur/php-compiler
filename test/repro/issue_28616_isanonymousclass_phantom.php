<?php

declare(strict_types=1);

// Repro #28616 — free-function isAnonymousClass() is absent from php-src; ReflectionClass::isAnonymous remains.

if (function_exists('isAnonymousClass')) {
    echo "fail: function_exists(isAnonymousClass) true\n";
    exit(1);
}

$anon = new class {};
if (!(new ReflectionClass($anon))->isAnonymous()) {
    echo "fail: ReflectionClass::isAnonymous() false for anonymous class\n";
    exit(1);
}
if ((new ReflectionClass(stdClass::class))->isAnonymous()) {
    echo "fail: ReflectionClass::isAnonymous() true for stdClass\n";
    exit(1);
}

echo "ok: isAnonymousClass withheld; ReflectionClass::isAnonymous works\n";
