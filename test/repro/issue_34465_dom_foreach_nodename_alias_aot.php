<?php
/**
 * #34465 — AOT foreach over childNodes must not alias/corrupt live nodeName (peer #33849).
 */
$d = new DOMDocument();
$d->loadXML('<r><a/><b/><c/></r>');
$r = $d->documentElement;
$held = $r->firstChild;
foreach ($r->childNodes as $n) {
    $sink = $n->nodeName;
}
echo 'sink_last=', $sink, "\n";
echo 'held_node=', $held->nodeName, ' held_tag=', $held->tagName, "\n";
echo 'first_node=', $r->firstChild->nodeName, ' last_node=', $r->lastChild->nodeName, "\n";
$out = [];
foreach ($r->childNodes as $n) {
    $out[] = $n->nodeName;
}
echo 'joined=', implode(',', $out), "\n";
echo 'after_first=', $r->firstChild->nodeName, '|', $r->lastChild->nodeName, "\n";
