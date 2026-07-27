<?php
// ++ on fopen resource must TypeError like Zend (#23777).
$fh = fopen('php://memory', 'r+');
try {
    ++$fh;
    echo "no error\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
