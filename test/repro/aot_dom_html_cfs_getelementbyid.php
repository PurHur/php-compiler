<?php

declare(strict_types=1);

/**
 * AOT Dom\HTMLDocument::createFromString + getElementById must not SIGABRT.
 * php-src: ext/dom/html_document.c / php_dom.c php_dom_get_element_by_id
 * Requires PHP_COMPILER_PROFILE=8.4 (living Dom\ API).
 */
$html = Dom\HTMLDocument::createFromString(
    '<html><body><div id="p"><span id="c">x</span></div></body></html>',
    LIBXML_NOERROR
);
$el = $html->getElementById('p');
echo $el ? $el->tagName : 'null', "\n";
$c = $html->getElementById('c');
echo $c ? $c->textContent : 'null', "\n";
$missing = $html->getElementById('nope');
echo $missing ? 'hit' : 'null', "\n";
