<?php

declare(strict_types=1);

try {
    array_fill(1, 2.9, 'x');
    echo "fail\n";
} catch (TypeError $e) {
    echo "ok\n";
}
