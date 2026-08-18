<?php
declare(strict_types=1);

/**
 * AOT DOMCharacterData::appendData() must not abort as DOMText::appenddata() (#32376).
 * php-src ext/dom/characterdata.c php_dom_characterdata_append_data / xmlTextConcat.
 */
$doc = new DOMDocument();
$text = $doc->createTextNode('ab');
$text->appendData('cd');
echo $text->data, "\n";
