<?php
declare(strict_types=1);

/**
 * AOT DOMCharacterData::insertData() must not abort as DOMText::insertdata() (#32380).
 * php-src ext/dom/characterdata.c PHP_METHOD(DOMCharacterData, insertData) → xmlTextInsert.
 */
$doc = new DOMDocument();
$text = $doc->createTextNode('ac');
$text->insertData(1, 'b');
echo $text->data, "\n";
