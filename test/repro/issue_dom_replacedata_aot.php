<?php
declare(strict_types=1);

/**
 * AOT DOMCharacterData::replaceData() must not abort as DOMText::replacedata() (#32392).
 * php-src ext/dom/characterdata.c PHP_METHOD(DOMCharacterData, replaceData).
 */
$doc = new DOMDocument();
$text = $doc->createTextNode('abcd');
$text->replaceData(1, 2, 'XY');
echo $text->data, "\n";
