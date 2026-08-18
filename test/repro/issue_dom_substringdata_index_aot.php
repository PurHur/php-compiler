<?php
declare(strict_types=1);

/**
 * AOT DOMCharacterData::substringData INDEX_SIZE_ERR in try/catch (#32372 leftover).
 * php-src ext/dom/characterdata.c php_dom_characterdata_substring_data.
 */
$doc = new DOMDocument();
$t = $doc->createTextNode('ab');
try {
    echo $t->substringData(-1, 1);
} catch (DOMException $e) {
    echo $e->getMessage(), "\n";
}
try {
    echo $t->substringData(3, 1);
} catch (DOMException $e) {
    echo $e->getMessage(), "\n";
}
