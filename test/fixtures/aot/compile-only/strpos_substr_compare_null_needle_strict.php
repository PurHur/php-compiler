<?php
// Compile-only (#17270): strpos()/substr_compare() must lower null needle TypeError guards for AOT.
declare(strict_types=1);
foreach (['strpos', 'substr_compare'] as $fn) {
    try {
        if ('strpos' === $fn) {
            $fn('haystack', null);
        } else {
            $fn('a', null, 0);
        }
        echo "{$fn}: no throw\n";
    } catch (TypeError $e) {
        echo "{$fn}: ", $e->getMessage(), "\n";
    }
}
