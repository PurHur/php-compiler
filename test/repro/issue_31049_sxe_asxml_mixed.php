<?php
// Issue #31049 — SimpleXMLElement::asXML() attributed text + mixed content (php-src sxe.c xmlNodeDump)
$xml = simplexml_load_string('<r a="1"><c b="2">hi</c><c>ho</c><d>x<e>y</e>z</d></r>');
$c0 = $xml->c[0];
echo 'c0_asxml=', str_replace(["\n", "\r"], '', (string) $c0->asXML()), "\n";
echo 'c0_string=', (string) $c0, "\n";
$d = $xml->d;
echo 'd_asxml=', str_replace(["\n", "\r"], '', (string) $d->asXML()), "\n";
echo 'root_asxml=', str_replace(["\n", "\r"], '', (string) $xml->asXML()), "\n";
$c0[0] = 'changed';
echo 'after_asxml=', str_replace(["\n", "\r"], '', (string) $c0->asXML()), "\n";
