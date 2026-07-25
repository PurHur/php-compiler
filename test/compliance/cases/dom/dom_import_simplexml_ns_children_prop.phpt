--TEST--
dom_import_simplexml() on namespaced children() property (#22829, #22728)
--FILE--
<?php
$s = simplexml_load_string('<r xmlns:x="urn:x"><x:a>1</x:a><b>2</b></r>');
$c = $s->children('urn:x');
echo 'isset_a=', isset($c->a) ? '1' : '0';
echo ' str_a=', (string) $c->a;
echo ' isset_b=', isset($c->b) ? '1' : '0', "\n";

$n = dom_import_simplexml($c->a);
echo $n->namespaceURI, '|', $n->localName, '|', $n->prefix, "\n";

$c2 = $s->children('x', true);
$n2 = dom_import_simplexml($c2->a);
echo $n2->namespaceURI, '|', $n2->localName, '|', $n2->prefix, "\n";

$plain = $s->children();
echo 'plain_b=', (string) $plain->b;
echo ' plain_isset_a=', isset($plain->a) ? '1' : '0', "\n";
?>
--EXPECT--
isset_a=1 str_a=1 isset_b=0
urn:x|a|x
urn:x|a|x
plain_b=2 plain_isset_a=0
