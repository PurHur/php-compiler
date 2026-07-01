<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root><parent><child/></parent><sibling/></root>');
$root = $doc->documentElement;
$parent = $root->firstChild;
$child = $parent->firstChild;
$sibling = $root->lastChild;

if (!$root->contains($child)) {
    fwrite(STDERR, "fail: root should contain descendant child\n");
    exit(1);
}
if (!$parent->contains($child)) {
    fwrite(STDERR, "fail: parent should contain child\n");
    exit(1);
}
if ($child->contains($root)) {
    fwrite(STDERR, "fail: child must not contain ancestor\n");
    exit(1);
}
if (!$root->contains($sibling)) {
    fwrite(STDERR, "fail: root should contain sibling\n");
    exit(1);
}
if (!$root->contains($root)) {
    fwrite(STDERR, "fail: node should contain itself\n");
    exit(1);
}
if ($root->contains(null)) {
    fwrite(STDERR, "fail: contains(null) should be false\n");
    exit(1);
}

echo "ok\n";
