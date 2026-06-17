<?php
$h = fopen('php://memory', 'r+');
fwrite($h, "ab\ncd");
rewind($h);
try {
    var_dump(fgets($h, 0));
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
fclose($h);
