<?php

declare(strict_types=1);

/**
 * #21958 — catastrophic `(?:\D+|<\d+>)*[!?]` must set PREG_BACKTRACK_LIMIT_ERROR
 * (php-src ext/pcre/php_pcre.c; re-#12289).
 */
@preg_match('/(?:\D+|<\d+>)*[!?]/', 'foobar foobar foobar');
echo 'code=' . preg_last_error() . "\n";
echo 'msg=' . preg_last_error_msg() . "\n";
echo (preg_last_error() === PREG_BACKTRACK_LIMIT_ERROR ? 'true' : 'false') . "\n";
