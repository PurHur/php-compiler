<?php
/**
 * Repro #27927 — range() with INF/-INF bounds throws Zend ValueError.
 * php-src: ext/standard/array.c
 */
foreach ([[0, INF], [INF, 0], [0, -INF]] as $args) {
    try {
        $r = range($args[0], $args[1]);
        echo 'ok:', count($r), "\n";
    } catch (ValueError $e) {
        echo 'ValueError:', $e->getMessage(), "\n";
    }
}
