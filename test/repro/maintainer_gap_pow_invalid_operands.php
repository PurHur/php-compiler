<?php

declare(strict_types=1);

foreach ([[], new stdClass()] as $bad) {
    try {
        pow($bad, 3);
        echo "fail: no exception for ", get_debug_type($bad), "\n";
        exit(1);
    } catch (TypeError $e) {
        if (!str_starts_with($e->getMessage(), 'Unsupported operand types:')) {
            echo 'fail: unexpected message: ', $e->getMessage(), "\n";
            exit(1);
        }
    }
}

echo "ok\n";
