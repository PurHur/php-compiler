--TEST--
SimpleXMLElement::__construct(null) soft-null DEP then Exception (#31514, ext/simplexml/sxe.c)
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
    $x = new SimpleXMLElement(null);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
DEP:SimpleXMLElement::__construct(): Passing null to parameter #1 ($data) of type string is deprecated
Exception: String could not be parsed as XML
