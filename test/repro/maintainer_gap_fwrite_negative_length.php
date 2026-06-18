<?php

$h = fopen('php://memory', 'r+');
try {
    var_dump(fwrite($h, 'x', -1));
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
fclose($h);
