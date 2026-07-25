--TEST--
SimpleXMLElement::children($ns) property __get/isset by local name (#22728, re-#21667)
--FILE--
<?php
$x = simplexml_load_string('<r xmlns:a="urn:a"><a:x>1</a:x><y>2</y><x>3</x></r>');
$c = $x->children('urn:a');
echo 'uri_isset=', isset($c->x) ? '1' : '0';
echo ' uri_str=', (string) $c->x;
echo ' uri_y=', isset($c->y) ? '1' : '0', "\n";

$c2 = $x->children('a', true);
echo 'pfx_isset=', isset($c2->x) ? '1' : '0';
echo ' pfx_str=', (string) $c2->x, "\n";

$plain = $x->children();
echo 'plain_x=', (string) $plain->x;
echo ' plain_y=', (string) $plain->y;
echo ' plain_isset_ns=', isset($plain->x) ? '1' : '0', "\n";
?>
--EXPECT--
uri_isset=1 uri_str=1 uri_y=0
pfx_isset=1 pfx_str=1
plain_x=3 plain_y=2 plain_isset_ns=1
