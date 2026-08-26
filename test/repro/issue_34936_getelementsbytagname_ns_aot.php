<?php
$d = new DOMDocument();
$d->loadXML('<r xmlns:x="urn:x"><x:a>hi</x:a></r>');
$list = $d->getElementsByTagName('a');
echo 'len='.$list->length."\n";
$item = $list->item(0);
echo 'item='.(null === $item ? 'NULL' : get_class($item).':'.$item->tagName.':'.$item->textContent)."\n";
$listNs = $d->getElementsByTagNameNS('urn:x', 'a');
echo 'nslen='.$listNs->length."\n";
$itemNs = $listNs->item(0);
echo 'nsitem='.(null === $itemNs ? 'NULL' : get_class($itemNs).':'.$itemNs->tagName.':'.$itemNs->textContent)."\n";
$d2 = new DOMDocument();
$d2->loadXML('<r><a>hi</a></r>');
$plain = $d2->getElementsByTagName('a')->item(0);
echo 'plain='.(null === $plain ? 'NULL' : get_class($plain).':'.$plain->tagName.':'.$plain->textContent)."\n";
