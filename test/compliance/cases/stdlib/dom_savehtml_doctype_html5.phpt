--TEST--
Stdlib: DOMDocument::saveHTML() preserves HTML5 short doctype (#15273, ext/dom/php_dom.c)
--FILE--
<?php
$d = new DOMDocument();
$d->loadHTML('<!DOCTYPE html><html><body></body></html>');
$html = $d->saveHTML();
echo str_contains($html, 'PUBLIC') ? "has_public\n" : "no_public\n";
echo str_starts_with($html, '<!DOCTYPE html>') ? "short_doctype\n" : "other_doctype\n";
?>
--EXPECT--
no_public
short_doctype
