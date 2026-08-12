--TEST--
XMLReader::XML/open(null) strict_types — TypeError null given (#30563)
--FILE--
<?php
declare(strict_types=1);
error_reporting(E_ALL);
try {
    XMLReader::XML(null);
    echo "XML:no-error\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $r = new XMLReader();
    $r->open(null);
    echo "open:no-error\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECT--
TypeError:XMLReader::XML(): Argument #1 ($source) must be of type string, null given
TypeError:XMLReader::open(): Argument #1 ($uri) must be of type string, null given
