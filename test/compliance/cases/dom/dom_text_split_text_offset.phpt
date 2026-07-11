--TEST--
dom DOMText::splitText() offset validation (#17541 #17542, ext/dom/text.c)
--FILE--
<?php
$doc = new DOMDocument();
$text = $doc->createTextNode('ab');
try {
    $text->splitText(-1);
    echo "noexception\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
var_export($doc->createTextNode('a')->splitText(5));
echo "\n";
?>
--EXPECT--
DOMText::splitText(): Argument #1 ($offset) must be greater than or equal to 0
false
