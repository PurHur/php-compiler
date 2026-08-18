--TEST--
AOT: DOMText::splitText offset ValueError and false (#32362, ext/dom/text.c)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
try {
    $doc->createTextNode('ab')->splitText(-1);
    echo "noexception\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
var_export((new DOMDocument())->createTextNode('a')->splitText(5));
echo "\n";
--EXPECT--
DOMText::splitText(): Argument #1 ($offset) must be greater than or equal to 0
false
