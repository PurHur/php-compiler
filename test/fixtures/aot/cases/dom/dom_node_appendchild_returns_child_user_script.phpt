--TEST--
AOT: DOMNode::appendChild returns the same child object (#27480, php-src ext/dom/node.c)
--FILE--
<?php
$d = new DOMDocument();
$r = $d->createElement('root');
$d->appendChild($r);
$a0 = $d->createElement('a');
$a = $r->appendChild($a0);
echo ($a === $a0 ? 'same' : 'diff'), '|', $a->nodeName, '|',
    ($a->parentNode === null ? 'null' : 'set'), "\n";
?>
--EXPECT--
same|a|set
