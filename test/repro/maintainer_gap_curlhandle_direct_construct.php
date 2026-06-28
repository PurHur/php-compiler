<?php

declare(strict_types=1);

/**
 * Maintainer repro: CurlHandle direct construct forbidden (ext/curl/interface.c, #11791).
 */

$handles = [
    'CurlHandle' => 'curl_init()',
    'CurlMultiHandle' => 'curl_multi_init()',
    'CurlShareHandle' => 'curl_share_init()',
];

foreach ($handles as $class => $hint) {
    if (!class_exists($class, false)) {
        echo "skip: {$class} not registered (ext/curl not loaded)\n";
        continue;
    }
    try {
        new $class();
        echo "fail: new {$class}() succeeded\n";
        exit(1);
    } catch (Error $e) {
        $expected = "Cannot directly construct {$class}, use {$hint} instead";
        if ($expected !== $e->getMessage()) {
            echo "fail: {$class} message={$e->getMessage()}\n";
            exit(1);
        }
        echo "{$class}:ok\n";
    }
}

echo "ok\n";
exit(0);
