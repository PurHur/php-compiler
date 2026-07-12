<?php

declare(strict_types=1);

enum Color: string {
    case Red = 'red';
}

foreach ([null, Color::Red] as $bad) {
    try {
        array_is_list($bad);
        echo "no throw for ", get_debug_type($bad), "\n";
    } catch (TypeError $e) {
        echo get_debug_type($bad), ': ', $e->getMessage(), "\n";
    }
}
