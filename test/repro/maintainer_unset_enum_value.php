<?php
declare(strict_types=1);
enum Color: string { case Red = 'red'; }
$c = Color::Red;
try {
    unset($c->value);
    echo "OK: unset succeeded\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
