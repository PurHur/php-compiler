<?php
/**
 * #22005 — DOMCharacterData OOB throws DOMException "Index Size Error" (php-src/libxml Title Case).
 * php-src: ext/dom/characterdata.c
 */
$d = new DOMDocument();
$t = $d->createTextNode('ab');
try {
    $t->substringData(5, 1);
    echo "FAIL: no throw\n";
    exit(1);
} catch (DOMException $e) {
    echo $e->getMessage(), "\n";
    echo $e->getCode(), "\n";
    exit($e->getMessage() === 'Index Size Error' && $e->getCode() === 1 ? 0 : 1);
}
