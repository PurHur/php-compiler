--TEST--
xmlreader XMLReader::open('') ValueError names Argument #1 ($uri) (#24810, ext/xmlreader/php_xmlreader.c)
--FILE--
<?php
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
?>
--EXPECT--
ValueError:XMLReader::open(): Argument #1 ($uri) cannot be empty
ValueError:XMLReader::XML(): Argument #1 ($source) cannot be empty
ValueError:XMLReader::open(): Argument #1 ($uri) cannot be empty
