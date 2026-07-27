<?php
$fh = fopen('php://memory', 'r+');
try {
    ++$fh;
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
fclose($fh);
