<?php
/**
 * DOMNode::C14N() must emit processing instructions (php-src ext/dom/node.c / libxml C14N; #21659).
 */
$doc = new DOMDocument();
$doc->loadXML('<r><!--c--><?pi d?><x/></r>');
$el = $doc->documentElement;

$got = $el->C14N();
if ('<r><?pi d?><x></x></r>' !== $got) {
    fwrite(STDERR, 'fail: default C14N got '.var_export($got, true)."\n");
    exit(1);
}

$gotComments = $el->C14N(false, true);
if ('<r><!--c--><?pi d?><x></x></r>' !== $gotComments) {
    fwrite(STDERR, 'fail: withComments C14N got '.var_export($gotComments, true)."\n");
    exit(1);
}

$pi = null;
foreach ($el->childNodes as $child) {
    if (XML_PI_NODE === $child->nodeType) {
        $pi = $child;
        break;
    }
}
if (null === $pi) {
    fwrite(STDERR, "fail: missing PI child\n");
    exit(1);
}
$piAlone = $pi->C14N();
// libxml may append a trailing newline for PI/comment-only C14N dumps; accept either.
$piAloneNorm = rtrim($piAlone, "\n");
if ('<?pi d?>' !== $piAloneNorm) {
    fwrite(STDERR, 'fail: PI alone C14N got '.var_export($piAlone, true)."\n");
    exit(1);
}

$emptyDoc = new DOMDocument();
$emptyDoc->loadXML('<r><?empty?></r>');
$emptyPi = $emptyDoc->documentElement->firstChild;
$emptyGot = rtrim($emptyPi->C14N(), "\n");
if ('<?empty?>' !== $emptyGot) {
    fwrite(STDERR, 'fail: empty PI C14N got '.var_export($emptyGot, true)."\n");
    exit(1);
}

$preamble = new DOMDocument();
$preamble->loadXML('<?xml version="1.0"?><!--docc--><?top?><r><?inner?></r><?after?>');
$docC14n = $preamble->C14N();
$expectedDoc = "<?top?>\n<r><?inner?></r>\n<?after?>";
if ($expectedDoc !== $docC14n) {
    fwrite(STDERR, 'fail: document C14N got '.var_export($docC14n, true)."\n");
    exit(1);
}
$docC14nCom = $preamble->C14N(false, true);
$expectedDocCom = "<!--docc-->\n<?top?>\n<r><?inner?></r>\n<?after?>";
if ($expectedDocCom !== $docC14nCom) {
    fwrite(STDERR, 'fail: document withComments C14N got '.var_export($docC14nCom, true)."\n");
    exit(1);
}

echo "ok\n";
