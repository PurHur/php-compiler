<?php
declare(strict_types=1);

/**
 * AOT DOMCharacterData::deleteData() must not abort as DOMText::deletedata() (#32389).
 * php-src ext/dom/characterdata.c PHP_METHOD(DOMCharacterData, deleteData) → xmlUTF8Strsub.
 */
$doc = new DOMDocument();
$text = $doc->createTextNode('abcd');
$text->deleteData(1, 2);
echo $text->data, "\n";
