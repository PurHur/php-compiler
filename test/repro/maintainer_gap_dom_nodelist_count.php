<?php
// Issue #14517 — DOMNodeList::count() Countable parity.
$doc = new DOMDocument();
$doc->loadXML('<root><a/><b/></root>');
$root = $doc->documentElement;
$childNodes = $root->childNodes;
echo 'childNodes count method='.(method_exists($childNodes, 'count') ? 'yes' : 'no')."\n";
if (method_exists($childNodes, 'count')) {
    echo 'count='.$childNodes->count().' length='.$childNodes->length."\n";
    echo 'builtin_count='.count($childNodes)."\n";
}
$tagList = $doc->getElementsByTagName('a');
echo 'tagList count method='.(method_exists($tagList, 'count') ? 'yes' : 'no')."\n";
if (method_exists($tagList, 'count')) {
    echo 'tag count='.$tagList->count().' length='.$tagList->length."\n";
}
