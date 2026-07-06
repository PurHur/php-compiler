<?php

declare(strict_types=1);

/**
 * Forward 8.4 profile: grapheme helpers callable but not advertised without ext/intl (#11825).
 */

if (\function_exists('grapheme_str_contains') || \function_exists('grapheme_strimwidth')) {
    echo "fail: function_exists true without intl\n";
    exit(1);
}
if (!grapheme_str_contains('hello', 'ell') || 'hello' !== grapheme_strimwidth('hello', 0, 10)) {
    echo "fail: callable but wrong result\n";
    exit(1);
}
echo "ok\n";
