--TEST--
SimpleXML xpath results + property children live after unset (#20483, ext/simplexml/sxe.c)
--FILE--
<?php
$sx = simplexml_load_string('<r><a>1</a><a>2</a></r>');
$nodes = $sx->xpath('a');
echo 'before=', count($nodes), ':', (string) $nodes[0], ':', (string) $nodes[1], "\n";
unset($sx->a[0]);
echo 'xml=', trim($sx->asXML()), "\n";
echo 'after=', count($nodes), ':', (string) $nodes[0], ':', (string) $nodes[1], "\n";
echo 'children_left=', count($sx->a), "\n";

$held = simplexml_load_string('<r><a>1</a><a>2</a></r>');
$a = $held->a;
unset($a[0]);
echo 'held_after=', count($a), ':', (string) $a, "\n";
$held->addChild('a', '3');
echo 'held_add=', count($a), "\n";
?>
--EXPECT--
before=2:1:2
xml=<?xml version="1.0"?>
<r><a>2</a></r>
after=2::2
children_left=1
held_after=1:2
held_add=2
