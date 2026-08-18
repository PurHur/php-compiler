<?php
declare(strict_types=1);

/**
 * AOT DOMCharacterData::replaceData() must not abort as DOMText::replacedata() (#32391).
 * php-src ext/dom/characterdata.c PHP_METHOD(DOMCharacterData, replaceData).
 */
$doc = new DOMDocument();
$text = $doc->createTextNode('axc');
$text->replaceData(1, 1, 'b');
echo $text->data, "\n";
