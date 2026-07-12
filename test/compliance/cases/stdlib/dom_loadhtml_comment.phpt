--TEST--
stdlib DOMDocument::loadHTML() preserves body comment siblings (#17534, ext/dom/html_document.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadHTML('<html><body><!--c--><p>x</p></body></html>');
$html = $doc->saveHTML();
echo (int) (false !== strpos($html, '<!--c-->')), "\n";
echo (int) (false !== strpos($html, '<p>x</p>')), "\n";
$doc2 = new DOMDocument();
$doc2->loadHTML('<html><body>text<!--c--><p>x</p></body></html>');
$html2 = $doc2->saveHTML();
echo (int) (false !== strpos($html2, 'text')), "\n";
echo (int) (false !== strpos($html2, '<!--c-->')), "\n";
echo (int) (false !== strpos($html2, '<p>x</p>')), "\n";
?>
--EXPECT--
1
1
1
1
1
