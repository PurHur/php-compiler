<?php
declare(strict_types=1);

/**
 * AOT DOMCharacterData::substringData() must not abort as object::substringdata() (#32372).
 * php-src ext/dom/characterdata.c php_dom_characterdata_substring_data.
 */
$doc = new DOMDocument();
$t = $doc->createTextNode('abcd');
echo $t->substringData(1, 2), '|', $t->substringData(0, 4), "\n";
