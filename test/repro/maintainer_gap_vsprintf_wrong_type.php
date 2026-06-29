<?php
declare(strict_types=1);

foreach (['vsprintf', 'vprintf'] as $fn) {
    try {
        $fn('%s', 'hi');
        echo "$fn: uncaught\n";
    } catch (TypeError $e) {
        echo "$fn: ", $e->getMessage(), "\n";
    }
}
