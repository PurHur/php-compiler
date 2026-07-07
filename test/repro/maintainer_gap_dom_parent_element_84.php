<?php

declare(strict_types=1);

putenv('PHP_COMPILER_PROFILE=8.4');
$_ENV['PHP_COMPILER_PROFILE'] = '8.4';
$_SERVER['PHP_COMPILER_PROFILE'] = '8.4';

$doc = new DOMDocument();
$doc->loadHTML('<html><body><div id="x"></div></body></html>');
$div = $doc->getElementById('x');
if (null === $div) {
    echo "fail: div missing\n";
    exit(1);
}

$parent = $div->parentElement;
if (null === $parent) {
    echo "fail: parentElement missing\n";
    exit(1);
}

echo $parent->tagName, "\n";
echo ($div->parentElement === $parent ? 'same' : 'diff'), "\n";

$text = $doc->createTextNode('hi');
$div->appendChild($text);
echo ($text->parentElement === $div ? 'text_parent_ok' : 'text_parent_fail'), "\n";
echo ($doc->documentElement->parentElement === null ? 'doc_root_null' : 'doc_root_fail'), "\n";
echo "dom_parent_element_ok=1\n";
