<?php
declare(strict_types=1);
enum Color: string { case Red = 'red'; }
try {
    $r = preg_filter('/red/', 'x', Color::Red);
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
