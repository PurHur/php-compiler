--TEST--
SimpleXMLElement::addChild/addAttribute(null) soft-null DEP then empty ValueError (#31554, ext/simplexml/sxe.c)
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
$x = new SimpleXMLElement('<r/>');
try {
    $x->addChild(null);
    echo "addChild ok\n";
} catch (Throwable $e) {
    echo 'addChild ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $x->addAttribute(null, 'v');
    echo "addAttribute ok\n";
} catch (Throwable $e) {
    echo 'addAttribute ', get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
DEP:SimpleXMLElement::addChild(): Passing null to parameter #1 ($qualifiedName) of type string is deprecated
addChild ValueError: SimpleXMLElement::addChild(): Argument #1 ($qualifiedName) cannot be empty
DEP:SimpleXMLElement::addAttribute(): Passing null to parameter #1 ($qualifiedName) of type string is deprecated
addAttribute ValueError: SimpleXMLElement::addAttribute(): Argument #1 ($qualifiedName) cannot be empty
