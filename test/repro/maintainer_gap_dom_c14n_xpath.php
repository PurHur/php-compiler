<?php
/**
 * DOMNode::C14N() xpath nodeset filter + DOMXPath relative `.` / `.//` (php-src ext/dom/node.c; #20257).
 *
 * Bool literals in multi-arg calls with an array literal are a separate compiler ARG_SEND
 * coalescing gap — use variables for exclusive/withComments so this repro isolates DOM semantics.
 */
$doc = new DOMDocument();
$doc->loadXML('<root xmlns:a="urn:a"><a:x id="1">A</a:x><y id="2">B</y><z><!--c-->C</z></root>');
$el = $doc->documentElement;

$xp = new DOMXPath($doc);
$rel = $xp->query('.//y', $el);
if (1 !== $rel->length || 'y' !== $rel->item(0)->nodeName) {
    fwrite(STDERR, "fail: relative .//y length/name\n");
    exit(1);
}
$self = $xp->query('.', $el);
if (1 !== $self->length || 'root' !== $self->item(0)->nodeName) {
    fwrite(STDERR, "fail: relative . self\n");
    exit(1);
}

$exclusive = false;
$withComments = false;
$got = $el->C14N($exclusive, $withComments, ['query' => './/y']);
if ('<y></y>' !== $got) {
    fwrite(STDERR, 'fail: C14N .//y got '.var_export($got, true)."\n");
    exit(1);
}

$gotStar = $el->C14N($exclusive, $withComments, ['query' => './/*']);
if ('<a:x></a:x><y></y><z></z>' !== $gotStar) {
    fwrite(STDERR, 'fail: C14N .//* got '.var_export($gotStar, true)."\n");
    exit(1);
}

$gotSelf = $el->C14N($exclusive, $withComments, ['query' => '.']);
if ('<root></root>' !== $gotSelf) {
    fwrite(STDERR, 'fail: C14N . got '.var_export($gotSelf, true)."\n");
    exit(1);
}

$withAttr = $el->C14N($exclusive, $withComments, ['query' => './/y | .//y/@id']);
if ('<y id="2"></y>' !== $withAttr) {
    fwrite(STDERR, 'fail: C14N y|@id got '.var_export($withAttr, true)."\n");
    exit(1);
}

try {
    $el->C14N($exclusive, $withComments, ['foo' => 'bar']);
    fwrite(STDERR, "fail: expected ValueError for missing query key\n");
    exit(1);
} catch (ValueError $e) {
    if (!str_contains($e->getMessage(), 'must have a "query" key')) {
        fwrite(STDERR, 'fail: ValueError message '.$e->getMessage()."\n");
        exit(1);
    }
}

$empty = $el->C14N($exclusive, $withComments, ['query' => './/missing']);
if ('' !== $empty) {
    fwrite(STDERR, 'fail: empty nodeset got '.var_export($empty, true)."\n");
    exit(1);
}

echo "ok\n";
