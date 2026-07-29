<?php
// #24810 — XMLReader::open('') ValueError must name Argument #1 ($uri) (php-src php_xmlreader.c).
try {
    XMLReader::open('');
    echo "open:no-error\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    XMLReader::XML('');
    echo "XML:no-error\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $r = new XMLReader();
    $r->open('');
    echo "instance-open:no-error\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
