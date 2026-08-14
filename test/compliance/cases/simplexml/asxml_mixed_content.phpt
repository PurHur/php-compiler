--TEST--
SimpleXMLElement::asXML() preserves attributed text and mixed-content order (#31049, ext/simplexml/sxe.c)
--FILE--
<?php
$xml = simplexml_load_string('<r a="1"><c b="2">hi</c><c>ho</c><d>x<e>y</e>z</d></r>');
$c0 = $xml->c[0];
echo 'c0_asxml=', str_replace(["\n", "\r"], '', (string) $c0->asXML()), "\n";
echo 'c0_string=', (string) $c0, "\n";
$d = $xml->d;
echo 'd_asxml=', str_replace(["\n", "\r"], '', (string) $d->asXML()), "\n";
echo 'd_string=', (string) $d, "\n";
echo 'root_asxml=', str_replace(["\n", "\r"], '', (string) $xml->asXML()), "\n";
$c0[0] = 'changed';
echo 'after_asxml=', str_replace(["\n", "\r"], '', (string) $c0->asXML()), "\n";
echo 'after_string=', (string) $c0, "\n";
?>
--EXPECT--
c0_asxml=<c b="2">hi</c>
c0_string=hi
d_asxml=<d>x<e>y</e>z</d>
d_string=xz
root_asxml=<?xml version="1.0"?><r a="1"><c b="2">hi</c><c>ho</c><d>x<e>y</e>z</d></r>
after_asxml=<c b="2">changed</c>
after_string=changed
