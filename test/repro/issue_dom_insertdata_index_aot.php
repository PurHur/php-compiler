<?php
declare(strict_types=1);

/**
 * AOT DOMCharacterData::insertData INDEX_SIZE_ERR in try/catch (#32380).
 * php-src ext/dom/characterdata.c PHP_METHOD(DOMCharacterData, insertData).
 */
$doc = new DOMDocument();
$t = $doc->createTextNode('ab');
try {
    $t->insertData(-1, 'x');
    echo "noexception\n";
} catch (DOMException $e) {
    echo $e->getMessage(), "\n";
}
try {
    $t->insertData(3, 'x');
    echo "noexception\n";
} catch (DOMException $e) {
    echo $e->getMessage(), "\n";
}
echo $t->data, "\n";
