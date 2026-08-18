<?php
declare(strict_types=1);

/**
 * AOT DOMCharacterData::deleteData INDEX_SIZE_ERR in try/catch (#32389).
 * php-src ext/dom/characterdata.c PHP_METHOD(DOMCharacterData, deleteData).
 */
$doc = new DOMDocument();
$t = $doc->createTextNode('ab');
try {
    $t->deleteData(-1, 1);
    echo "noexception\n";
} catch (DOMException $e) {
    echo $e->getMessage(), "\n";
}
try {
    $t->deleteData(3, 1);
    echo "noexception\n";
} catch (DOMException $e) {
    echo $e->getMessage(), "\n";
}
echo $t->data, "\n";
