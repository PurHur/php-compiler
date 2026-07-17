<?php

declare(strict_types=1);

foreach (['inflate_init', 'deflate_init'] as $fn) {
    try {
        $fn(null);
        echo "$fn: uncaught\n";
    } catch (ValueError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo $fn, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}

$ctx = deflate_init(ZLIB_ENCODING_RAW);
echo is_object($ctx) ? "deflate_init raw ok\n" : "deflate_init raw fail\n";
$in = inflate_init(ZLIB_ENCODING_RAW);
echo is_object($in) ? "inflate_init raw ok\n" : "inflate_init raw fail\n";
