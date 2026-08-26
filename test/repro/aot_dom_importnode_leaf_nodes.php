<?php
// #35098 — AOT importNode(Comment/CDATA/PI) must copy the leaf (php-src xmlDocCopyNode).
// loadXML firstChild Comment:
$s = new DOMDocument();
$s->loadXML('<r><!--c--></r>');
$d = new DOMDocument();
$d->loadXML('<r/>');
$imp = $d->importNode($s->documentElement->firstChild, true);
echo 'comment_type=', $imp->nodeType, ' name=', $imp->nodeName, ' val=', $imp->nodeValue, "\n";
$d->documentElement->appendChild($imp);
echo 'comment_xml=', trim($d->saveXML($d->documentElement)), "\n";

// loadXML firstChild CDATA:
$s2 = new DOMDocument();
$s2->loadXML('<r><![CDATA[x]]></r>');
$d2 = new DOMDocument();
$d2->loadXML('<r/>');
$imp2 = $d2->importNode($s2->documentElement->firstChild, true);
echo 'cdata_type=', $imp2->nodeType, ' name=', $imp2->nodeName, ' val=', $imp2->nodeValue, "\n";
$d2->documentElement->appendChild($imp2);
echo 'cdata_xml=', trim($d2->saveXML($d2->documentElement)), "\n";

// loadXML firstChild PI:
$s3 = new DOMDocument();
$s3->loadXML('<r><?pi data?></r>');
$d3 = new DOMDocument();
$d3->loadXML('<r/>');
$imp3 = $d3->importNode($s3->documentElement->firstChild, true);
echo 'pi_type=', $imp3->nodeType, ' name=', $imp3->nodeName, ' val=', $imp3->nodeValue, "\n";
$d3->documentElement->appendChild($imp3);
echo 'pi_xml=', trim($d3->saveXML($d3->documentElement)), "\n";
