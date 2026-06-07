<?php
enum Name: string { case N = 'n'; }
enum Line: int { case L = 1; }
$file = '';
$line = 0;
try {
    headers_sent(Name::N, $line);
} catch (Throwable $e) {
    echo 'file slot: ', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
try {
    headers_sent($file, Line::L);
} catch (Throwable $e) {
    echo 'line slot: ', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
