<?php

/**
 * #30535 — dechex/decoct/decbin/octdec excess argc → ArgumentCountError (php-src math.c).
 */
error_reporting(E_ALL);

$cases = [
    static fn () => dechex(10, 1),
    static fn () => decoct(10, 1),
    static fn () => decbin(10, 1),
    static fn () => octdec('12', 1),
];

foreach ($cases as $fn) {
    try {
        $fn();
        echo "NO_THROW\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
