<?php

declare(strict_types=1);

/** AOT: removeChild of non-child must throw Not Found like Zend (#33599). */
$doc = new DOMDocument();
$el = $doc->createElement('root');
$doc->appendChild($el);
$sib = $doc->createElement('sib');
$doc->appendChild($sib);
$orphan = $doc->createElement('orphan');
$nested = $doc->createElement('nested');
$sib->appendChild($nested);

try {
    $el->removeChild($orphan);
    echo "ORPHAN:NO THROW\n";
} catch (Throwable $e) {
    echo 'ORPHAN:', $e->getMessage(), "\n";
}

try {
    $el->removeChild($nested);
    echo "WRONG:NO THROW\n";
} catch (Throwable $e) {
    echo 'WRONG:', $e->getMessage(), "\n";
}

echo 'sibLen=', $sib->childNodes->length, "\n";
