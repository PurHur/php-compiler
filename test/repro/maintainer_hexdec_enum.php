<?php
declare(strict_types=1);

enum Es: string {
    case B = 'ff';
}

try {
    echo hexdec(Es::B);
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage();
}
