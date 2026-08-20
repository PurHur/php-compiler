<?php
$doc = new DOMDocument();
$doc->loadXML('<r><a/><b/></r>');
$r = $doc->documentElement;
$list = $r->childNodes;
$z = $doc->createElement('z');
$r->prepend($z);
echo 'held_len=', $list->length, "\n";
echo 'held0=', $list->item(0)->nodeName, "\n";
echo 'held1=', $list->item(1)->nodeName, "\n";
echo 'held2=', $list->item(2)->nodeName, "\n";
echo 'refetch_len=', $r->childNodes->length, "\n";
echo 'refetch0=', $r->childNodes->item(0)->nodeName, "\n";
