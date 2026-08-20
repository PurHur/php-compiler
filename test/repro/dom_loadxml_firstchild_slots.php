<?php
// AOT loadXML firstChild slots (#33014)
$d = new DOMDocument();
$d->loadXML('<r><e id="x">hi</e></r>');
$e = $d->documentElement->firstChild;
echo 'text=', $e->textContent, "\n";
echo 'local=', $e->localName, "\n";
echo 'save=', $d->saveXML($e), "\n";
$e->setIdAttribute('id', true);
$found = $d->getElementById('x');
if ($found === null) {
    echo "byId=null\n";
} else {
    echo 'byId=', $found->textContent, "\n";
}
