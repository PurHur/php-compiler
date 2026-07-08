<?php

foreach (['array_first', 'array_last'] as $fn) {
    try {
        $fn([]);
        echo $fn, ": uncaught\n";
    } catch (ValueError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}

