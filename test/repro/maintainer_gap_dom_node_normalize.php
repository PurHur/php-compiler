<?php
$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
$root->append('hello', ' world');
$root->normalize();
$text = '';
foreach ($root->childNodes as $n) {
    $text .= $n->nodeValue;
}
$ok = 'hello world' === $text && 1 === $root->childNodes->length;
echo $ok ? "OK\n" : "FAIL text=$text len=" . $root->childNodes->length . "\n";
exit($ok ? 0 : 1);
