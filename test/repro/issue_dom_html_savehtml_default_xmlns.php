<?php

declare(strict_types=1);

/**
 * Dom\HTMLDocument HTML serialize must omit default XHTML xmlns (#31304; re-#22773/#26924).
 * Legacy XML saveHTML xmlns nsDef remains #30350.
 */
$html = Dom\HTMLDocument::createFromString('<p id="p">x</p>', LIBXML_NOERROR);
echo 'doc=', $html->saveHtml(), "\n";

$i = $html->createElement('i');
echo 'node=', $html->saveHtml($i), "\n";

$p = $html->getElementById('p');
$p->appendChild($html->createElement('br'));
echo 'inner=', $p->innerHTML, "\n";
