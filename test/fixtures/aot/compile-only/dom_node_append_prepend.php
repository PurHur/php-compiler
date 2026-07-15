<?php

declare(strict_types=1);

// Compile-only (#18951): object append/prepend + firstChild slot sync; string-mix execute follow-up.
$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
$a = $doc->createElement('a');
$b = $doc->createElement('b');
$root->append($a, $b);
$root2 = $doc->createElement('r2');
$doc->appendChild($root2);
$x = $doc->createElement('x');
$y = $doc->createElement('y');
$root2->prepend($x, $y);
echo "ok\n";
