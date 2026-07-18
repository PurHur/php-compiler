--TEST--
SimpleXMLElement::attributes() live view — addAttribute visible (#20332, ext/simplexml/sxe.c)
--FILE--
<?php
$x = simplexml_load_string('<r a="1"/>');
$attrs = $x->attributes();
echo 'before=', count($attrs), "\n";
$x->addAttribute('b', '2');
echo 'after=', count($attrs), "\n";
$out = [];
foreach ($attrs as $k => $v) {
    $out[] = $k.'='.(string) $v;
}
echo implode(',', $out), "\n";

$x2 = simplexml_load_string('<r xmlns:p="urn:p" p:a="1"/>');
$ns = $x2->attributes('urn:p');
echo 'ns_before=', count($ns), "\n";
$x2->addAttribute('p:d', '4', 'urn:p');
echo 'ns_after=', count($ns), "\n";
--EXPECT--
before=1
after=2
a=1,b=2
ns_before=1
ns_after=2
