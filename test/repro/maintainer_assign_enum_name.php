<?php
declare(strict_types=1);
enum Color: string { case Red = 'red'; }
$c = Color::Red;
try {
    $c->name = 'Blue';
    echo "OK: assigned name=" . $c->name . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
