<?php
/** Repro #26061 — Dom\Document::registerNodeClass Dom\* bases. */
class MyE extends Dom\Element {}
$doc = Dom\XMLDocument::createEmpty();
try {
    $doc->registerNodeClass(Dom\Element::class, MyE::class);
    echo 'ok ', get_class($doc->createElement('z')), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
    exit(1);
}

class MyH extends Dom\HTMLElement {}
$html = Dom\HTMLDocument::createEmpty();
$html->registerNodeClass(Dom\HTMLElement::class, MyH::class);
echo 'html ', get_class($html->createElement('div')), "\n";
