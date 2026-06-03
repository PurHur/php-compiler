<?php
enum Color: string { case Red = 'r'; }
$c = Color::Red;
try {
    echo "$c";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
