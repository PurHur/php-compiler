<?php

declare(strict_types=1);

/**
 * #31324 — AOT Dom\HTMLDocument::saveHtml() after createFromString (re-#31304/#27300).
 * Requires PHP_COMPILER_PROFILE=8.4 (living Dom\ namespace).
 */
$html = Dom\HTMLDocument::createFromString('<p id="p">x</p>', LIBXML_NOERROR);
echo 'doc=', $html->saveHtml(), "\n";

$i = $html->createElement('i');
echo 'node=', $html->saveHtml($i), "\n";
