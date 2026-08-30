<?php
declare(strict_types=1);

/**
 * #35792 — AOT Dom\HTMLDocument::createFromString + getElementById.
 * php-src: ext/dom/html_document.c, ext/dom/php_dom.c php_dom_get_element_by_id
 * Requires PHP_COMPILER_PROFILE=8.4 (living Dom\ API); host Zend 8.2 has no Dom\.
 */
$html = '<!DOCTYPE html><html><body><div id="p">hi</div></body></html>';
$doc = Dom\HTMLDocument::createFromString($html);
$el = $doc->getElementById('p');
if (null === $el) {
    echo "null\n";
} else {
    echo $el->tagName, "\n";
    echo $el->textContent, "\n";
}
$miss = $doc->getElementById('nope');
echo null === $miss ? "null\n" : "found\n";
