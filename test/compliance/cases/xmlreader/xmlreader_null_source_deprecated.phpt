--TEST--
XMLReader::XML/open(null) — Deprecated then ValueError empty (#30563, ext/xmlreader/php_xmlreader.c)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP:{$msg}\n";
        return true;
    }
    echo "E{$no}:{$msg}\n";
    return true;
});
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
DEP:XMLReader::XML(): Passing null to parameter #1 ($source) of type string is deprecated
ValueError:XMLReader::XML(): Argument #1 ($source) cannot be empty
DEP:XMLReader::open(): Passing null to parameter #1 ($uri) of type string is deprecated
ValueError:XMLReader::open(): Argument #1 ($uri) cannot be empty
