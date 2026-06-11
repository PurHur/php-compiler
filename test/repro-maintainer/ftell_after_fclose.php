<?php
$h = fopen('php://memory', 'r+');
fclose($h);
try {
    $r = ftell($h);
    var_dump($r);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
