--TEST--
AOT: DOMNode append/prepend user-script — object multi-arg + firstChild slots (#18951)
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

echo "ok\n";
--EXPECT--
ok
