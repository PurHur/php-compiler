<?php

declare(strict_types=1);

// Compile-only (#18951): DOMNode::append/prepend user-script bridges link; execute tier blocked on helper runtime.
$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
$a = $doc->createElement('a');
$b = $doc->createElement('b');
$root->append($a, $b);
$root2 = $doc->createElement('r2');
$doc->appendChild($root2);
$x = $doc->createElement('x');
$root2->prepend($x);
echo "ok\n";
