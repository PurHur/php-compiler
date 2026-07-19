<?php
$doc = Dom\XMLDocument::createFromString('<r><e>hi &amp; there</e></r>');
$el = $doc->documentElement->firstElementChild;
echo 'isset=', isset($el->substitutedNodeValue) ? 'yes' : 'no', "\n";
echo 'get=', var_export($el->substitutedNodeValue, true), "\n";
$el->substitutedNodeValue = 'x &amp; y';
echo 'text=', var_export($el->textContent, true), "\n";
echo 'subst=', var_export($el->substitutedNodeValue, true), "\n";
echo 'nv=', var_export($el->nodeValue, true), "\n";
