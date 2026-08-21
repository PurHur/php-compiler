<?php

declare(strict_types=1);

/** AOT: removeChild(Attr) must throw Not Found, not SIGSEGV (#33596). */
$doc = new DOMDocument();
$el = $doc->createElement('root');
$doc->appendChild($el);
$text = $doc->createTextNode('t');
$el->appendChild($text);
$attr = $doc->createAttribute('id');
$attr->value = 'x';

try {
    $el->removeChild($attr);
    echo "NO THROW\n";
} catch (Throwable $e) {
    // Thin-AOT get_class() on some exceptions is empty; message identifies Zend parity.
    echo $e->getMessage(), "\n";
}
