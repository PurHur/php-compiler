<?php

// #24462 — AOT: lone isEqualNode(null) (mixed call sites: use VM repro).

$doc = new DOMDocument();
$a = $doc->createElement('a');
$doc->appendChild($a);
echo 'null=', (int) $a->isEqualNode(null), "\n";
