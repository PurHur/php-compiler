--TEST--
AOT: DOMNode::insertBefore reorder siblings (#22686 smoke)
--FILE--
<?php
declare(strict_types=1);
$d = new DOMDocument();
$root = $d->createElement('root');
$d->appendChild($root);
$a = $d->createElement('a');
$b = $d->createElement('b');
$root->appendChild($a);
$root->appendChild($b);
$root->insertBefore($b, $a);
echo "ok\n";
--EXPECT--
ok
