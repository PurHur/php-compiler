--TEST--
SimpleXMLElement empty() on attribute dimensions (#25338, ext/simplexml/sxe.c)
--FILE--
<?php
$xml = simplexml_load_string('<root a="1" b="" c="0"/>');
echo 'empty_a=', empty($xml['a']) ? '1' : '0';
echo ' empty_b=', empty($xml['b']) ? '1' : '0';
echo ' empty_c=', empty($xml['c']) ? '1' : '0';
echo ' empty_missing=', empty($xml['missing']) ? '1' : '0', "\n";
$held = $xml['b'];
echo 'empty_held=', empty($held) ? '1' : '0';
echo ' bool_b=', ((bool) $xml['b']) ? '1' : '0', "\n";
$attrs = $xml->attributes();
echo 'empty_attrs_b=', empty($attrs['b']) ? '1' : '0';
echo ' empty_attrs_c=', empty($attrs['c']) ? '1' : '0', "\n";
$nodes = simplexml_load_string('<root><child><x>y</x></child><e></e><z>0</z></root>');
echo 'empty_child0=', empty($nodes->child[0]) ? '1' : '0';
echo ' empty_e0=', empty($nodes->e[0]) ? '1' : '0';
echo ' empty_z0=', empty($nodes->z[0]) ? '1' : '0', "\n";
?>
--EXPECT--
empty_a=0 empty_b=1 empty_c=1 empty_missing=1
empty_held=0 bool_b=1
empty_attrs_b=1 empty_attrs_c=1
empty_child0=0 empty_e0=1 empty_z0=1
