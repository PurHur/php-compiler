<?php

declare(strict_types=1);

/** AOT: insertBefore/replaceChild(Attr) must not SIGSEGV (#33587). */
$doc = new DOMDocument();
$el = $doc->createElement('root');
$doc->appendChild($el);
$text = $doc->createTextNode('t');
$el->appendChild($text);
$attr = $doc->createAttribute('id');
$attr->value = 'x';

echo "=== insertBefore ===\n";
try {
    $el->insertBefore($attr, $text);
    echo "NO THROW\n";
} catch (Throwable $e) {
    // Thin-AOT get_class() on some exceptions is empty; message identifies Zend parity.
    echo $e->getMessage(), "\n";
}

echo "=== replaceChild ===\n";
try {
    $el->replaceChild($attr, $text);
    echo "NO THROW\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
