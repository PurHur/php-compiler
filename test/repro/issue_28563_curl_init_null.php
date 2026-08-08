<?php

/**
 * #28563 — curl_init(?string $url = null) must accept null without Deprecated (ext/curl/curl.stub.php).
 *
 *   PHP_COMPILER_ENABLE_CURL=1 php bin/vm.php test/repro/issue_28563_curl_init_null.php
 */

declare(strict_types=1);

error_reporting(E_ALL);
$deps = 0;
set_error_handler(static function (int $n, string $m) use (&$deps): bool {
    if (E_DEPRECATED === $n && str_contains($m, 'null to parameter')) {
        ++$deps;
        fwrite(STDERR, "unexpected dep: {$m}\n");

        return true;
    }

    return false;
});

$ch = curl_init(null);
if (!is_object($ch) && !is_resource($ch)) {
    fwrite(STDERR, "fail: curl_init(null) did not return a handle\n");
    exit(1);
}

$ch2 = curl_init('https://example.com');
if (!is_object($ch2) && !is_resource($ch2)) {
    fwrite(STDERR, "fail: curl_init(url) did not return a handle\n");
    exit(1);
}

$r = new ReflectionFunction('curl_init');
$p = $r->getParameters()[0];
$type = $p->hasType() ? (string) $p->getType() : 'NONE';
if ('?string' !== $type) {
    fwrite(STDERR, "fail: Reflection type={$type}\n");
    exit(1);
}

if (0 !== $deps) {
    fwrite(STDERR, "fail: deps={$deps}\n");
    exit(1);
}

echo "ok\n";
