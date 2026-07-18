<?php

declare(strict_types=1);

// Issue #20483 — SimpleXMLElement::xpath() live handles + property count after unset
// (ext/simplexml/sxe.c).

$sx = simplexml_load_string('<r><a>1</a><a>2</a></r>');
$nodes = $sx->xpath('a');
echo 'before=', count($nodes), ':', (string) $nodes[0], ':', (string) $nodes[1], "\n";
unset($sx->a[0]);
echo 'xml=', trim($sx->asXML()), "\n";
echo 'after=', count($nodes), ':', (string) $nodes[0], ':', (string) $nodes[1], "\n";
echo 'children_left=', count($sx->a), "\n";
