--TEST--
AOT: DOMNode append/prepend user-script standalone — multi-arg + string mix (#18951, ext/dom/parentnode.c)
--FILE--
<?php

declare(strict_types=1);

$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);

$a = $doc->createElement('a');
$b = $doc->createElement('b');
$root->append($a, $b);

if ('a' !== $root->firstChild->nodeName || 'b' !== $root->lastChild->nodeName) {
    echo 'fail: append order ', $root->firstChild->nodeName, ',', $root->lastChild->nodeName, "\n";
    exit(1);
}

$root2 = $doc->createElement('r2');
$doc->appendChild($root2);
$x = $doc->createElement('x');
$y = $doc->createElement('y');
$root2->prepend($x, $y);

if ('x' !== $root2->firstChild->nodeName || 'y' !== $root2->lastChild->nodeName) {
    echo 'fail: prepend order ', $root2->firstChild->nodeName, ',', $root2->lastChild->nodeName, "\n";
    exit(1);
}

$p = $doc->createElement('p');
$doc->appendChild($p);
$p->append('hello', $doc->createElement('b'), ' world');
if ('hello world' !== $p->textContent || '#text' !== $p->firstChild->nodeName) {
    echo 'fail: append string mix ', $p->textContent, "\n";
    exit(1);
}

echo "ok\n";
--EXPECT--
ok
