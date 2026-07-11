--TEST--
Stdlib: DOMDocument::loadHTML() preserves PI preamble in childNodes (#15264, ext/dom/html_document.c)
--FILE--
<?php
$d = new DOMDocument();
$d->loadHTML('<?pi test?><html><body></body></html>');
$types = [];
foreach ($d->childNodes as $node) {
    $types[] = $node->nodeType;
}
echo implode(',', $types), "\n";
?>
--EXPECT--
10,7,1
