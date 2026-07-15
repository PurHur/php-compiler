--TEST--
AOT: DOMNode::append() user-script standalone — single object child (#18951, ext/dom/parentnode.c)
--FILE--
<?php

declare(strict_types=1);

$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
$a = $doc->createElement('a');
$root->append($a);
echo "ok\n";
--EXPECT--
ok
