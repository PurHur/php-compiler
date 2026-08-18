--TEST--
AOT: importNode(loadXML documentElement) must not SIGSEGV (#32350)
--FILE--
<?php
declare(strict_types=1);
$src = new DOMDocument();
$src->loadXML('<r><c/></r>');
$dst = new DOMDocument();
$n = $dst->importNode($src->documentElement, true);
echo $n->nodeName, '|', $dst->saveXML($n), "END\n";
--EXPECT--
r|<r><c/></r>END
--EXPECT_EXIT--
0
