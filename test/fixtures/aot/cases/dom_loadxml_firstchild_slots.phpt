--TEST--
AOT: loadXML firstChild textContent/localName/saveXML + setIdAttribute getElementById (#33014)
--FILE--
<?php
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
$d2 = new DOMDocument();
$d2->loadXML('<r id="root"><e id="x">hi</e></r>');
echo 'rootSave=', $d2->saveXML($d2->documentElement), "\n";
echo 'rootLocal=', $d2->documentElement->localName, "\n";
?>
--EXPECT--
text=hi
local=e
save=<e id="x">hi</e>
byId=hi
rootSave=<r id="root"><e id="x">hi</e></r>
rootLocal=r
