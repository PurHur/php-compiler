<?php

declare(strict_types=1);

foreach (['str_rot13', 'str_shuffle', 'str_repeat', 'hebrev'] as $fn) {
    try {
        if ('str_repeat' === $fn) {
            $fn(null, 2);
        } else {
            $fn(null);
        }
        echo "{$fn}: uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
