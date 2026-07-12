<?php

declare(strict_types=1);

foreach (['bin2hex', 'base64_encode', 'quoted_printable_encode', 'quoted_printable_decode'] as $fn) {
    try {
        $fn(null);
        echo "{$fn}: uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
