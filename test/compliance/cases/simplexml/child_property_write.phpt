--TEST--
SimpleXMLElement child property write ($sxe->child = …) (#20539, sxe_prop_dim_write)
--FILE--
<?php
$sx = simplexml_load_string('<r><a>old</a></r>');
$sx->a = 'new';
echo (string) $sx->a, "\n";
echo trim($sx->asXML()), "\n";

$sx->z = 'zz';
echo (string) $sx->z, "\n";
echo trim($sx->asXML()), "\n";

$sx2 = simplexml_load_string('<r><a><b>old</b></a></r>');
$sx2->a->b = 'new';
echo trim($sx2->asXML()), "\n";

$sx3 = simplexml_load_string('<r><a>1</a><b>2</b></r>');
$sx3->children()->a = 'X';
echo trim($sx3->asXML()), "\n";

$sx4 = simplexml_load_string('<r><a>old</a></r>');
$sx4->a = 42;
echo (string) $sx4->a, "\n";

$sx5 = simplexml_load_string('<r><a><b>x</b></a></r>');
$sx5->a = 'flat';
echo trim($sx5->asXML()), "\n";

$sx6 = simplexml_load_string('<r id="1"/>');
$sx6->attributes()->id = '9';
echo trim($sx6->asXML()), "\n";

$sx7 = simplexml_load_string('<r><a>1</a><a>2</a></r>');
$sx7->a = 'all?';
echo trim($sx7->asXML()), "\n";
?>
--EXPECTF--
PHP Warning:  Cannot assign to an array of nodes (duplicate subnodes or attr detected)%A
new
<?xml version="1.0"?>
<r><a>new</a></r>
zz
<?xml version="1.0"?>
<r><a>new</a><z>zz</z></r>
<?xml version="1.0"?>
<r><a><b>new</b></a></r>
<?xml version="1.0"?>
<r><a>X</a><b>2</b></r>
42
<?xml version="1.0"?>
<r><a>flat</a></r>
<?xml version="1.0"?>
<r id="9"/>
<?xml version="1.0"?>
<r><a>1</a><a>2</a></r>
