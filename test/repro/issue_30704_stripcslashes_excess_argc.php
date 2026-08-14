<?php

/**
 * Repro #30704 — stripcslashes() excess/missing argc → ArgumentCountError (Zend wording).
 * php-src: ext/standard/string.c PHP_FUNCTION(stripcslashes)
 */
foreach ([
    'hi' => static fn () => stripcslashes('a', 'x'),
    'lo' => static fn () => stripcslashes(),
] as $name => $call) {
    try {
        $call();
        echo $name, ":NO_THROW\n";
    } catch (Throwable $e) {
        echo $name, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
echo 'ok=', ('A' === stripcslashes('\\x41')) ? '1' : '0', "\n";
