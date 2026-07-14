--TEST--
AOT: DOMDocument::appendChild() user-script standalone (#18927, ext/dom/document.c)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
echo "ok\n";
--EXPECT--
ok
