<?php
declare(strict_types=1);

/**
 * VM DOMCharacterData::replaceData INDEX_SIZE_ERR (#32391).
 * php-src ext/dom/characterdata.c php_dom_characterdata_replace_data.
 */
$doc = new DOMDocument();
$t = $doc->createTextNode('ab');
try {
    $t->replaceData(-1, 1, 'x');
} catch (DOMException $e) {
    echo $e->getMessage(), "\n";
}
try {
    $t->replaceData(3, 1, 'x');
} catch (DOMException $e) {
    echo $e->getMessage(), "\n";
}
echo $t->data, "\n";
