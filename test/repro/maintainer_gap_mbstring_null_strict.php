<?php

/**
 * Issue #18243 — mb_strlen/mb_substr/mb_strtolower(null) must TypeError (ext/mbstring/mbstring.c).
 */

foreach (['mb_strlen', 'mb_substr', 'mb_strtolower'] as $fn) {
    try {
        if ('mb_substr' === $fn) {
            $fn(null, 0);
        } else {
            $fn(null);
        }
        echo $fn, ": uncaught\n";
        exit(1);
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
