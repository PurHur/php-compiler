--TEST--
stdlib DOMDocument::loadHTML() unquoted id — getElementById() parity (#18319, ext/dom/document.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadHTML('<p id=x>hi</p>');
$found = $doc->getElementById('x');
echo null === $found ? "null\n" : $found->textContent."\n";
$doc2 = new DOMDocument();
$doc2->loadHTML("<p id='y'>there</p>");
$found2 = $doc2->getElementById('y');
echo null === $found2 ? "null\n" : $found2->textContent."\n";
?>
--EXPECT--
hi
there
