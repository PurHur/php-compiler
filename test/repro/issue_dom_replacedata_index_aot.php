<?php
declare(strict_types=1);

/**
 * AOT DOMCharacterData::replaceData INDEX_SIZE_ERR in try/catch (#32392).
 * php-src ext/dom/characterdata.c PHP_METHOD(DOMCharacterData, replaceData).
 */
$doc = new DOMDocument();
$t = $doc->createTextNode('ab');
try {
    $t->replaceData(-1, 1, 'x');
    echo "noexception\n";
} catch (DOMException $e) {
    echo $e->getMessage(), "\n";
}
try {
    $t->replaceData(3, 1, 'x');
    echo "noexception\n";
} catch (DOMException $e) {
    echo $e->getMessage(), "\n";
}
try {
    $t->replaceData(1, -1, 'x');
    echo "noexception\n";
} catch (DOMException $e) {
    echo $e->getMessage(), "\n";
}
echo $t->data, "\n";
