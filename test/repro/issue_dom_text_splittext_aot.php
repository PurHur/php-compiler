<?php
declare(strict_types=1);

/**
 * AOT DOMText::splitText() must not abort as DOMText::splittext() (#32362).
 * php-src ext/dom/text.c PHP_METHOD(DOMText, splitText) → xmlTextSplitText.
 */
$doc = new DOMDocument();
$text = $doc->createTextNode('abcd');
$tail = $text->splitText(2);
echo $text->data, '|', $tail->data, "\n";
