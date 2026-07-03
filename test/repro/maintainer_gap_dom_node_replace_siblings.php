<?php

declare(strict_types=1);

$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
$a = $doc->createElement('a');
$b = $doc->createElement('b');
$root->appendChild($a);
$root->appendChild($b);

$before = $doc->createElement('before');
$b->before($before);
$beforeXml = $doc->saveXML($root);
if ('<root><a/><before/><b/></root>' !== $beforeXml) {
    fwrite(STDERR, "fail: before expected <root><a/><before/><b/></root> got {$beforeXml}\n");
    exit(1);
}

$after = $doc->createElement('after');
$b->after($after);
$afterXml = $doc->saveXML($root);
if ('<root><a/><before/><b/><after/></root>' !== $afterXml) {
    fwrite(STDERR, "fail: after got {$afterXml}\n");
    exit(1);
}

$repl = $doc->createElement('replace');
$b->replaceWith($repl);
$replaceXml = $doc->saveXML($root);
if ('<root><a/><before/><replace/><after/></root>' !== $replaceXml) {
    fwrite(STDERR, "fail: replace got {$replaceXml}\n");
    exit(1);
}

$repl->remove();
$removeXml = $doc->saveXML($root);
if ('<root><a/><before/><after/></root>' !== $removeXml) {
    fwrite(STDERR, "fail: remove got {$removeXml}\n");
    exit(1);
}

echo "before / after / replace / remove\n";
