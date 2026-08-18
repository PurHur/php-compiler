<?php
declare(strict_types=1);

/**
 * AOT DOMText::wholeText stays in sync after insertData (#32395).
 * php-src ext/dom/text.c dom_text_whole_text_read / characterdata.c xmlTextInsert.
 */
$doc = new DOMDocument();
$text = $doc->createTextNode('ac');
$text->insertData(1, 'b');
echo $text->wholeText, "\n";
