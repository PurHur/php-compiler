<?php
declare(strict_types=1);

/**
 * AOT DOMText::splitText offset: ValueError when < 0; false past length (#32362).
 * php-src ext/dom/text.c PHP_METHOD(DOMText, splitText).
 */
$doc = new DOMDocument();
try {
    $doc->createTextNode('ab')->splitText(-1);
    echo "noexception\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
var_export((new DOMDocument())->createTextNode('a')->splitText(5));
echo "\n";
