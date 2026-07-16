<?php
/** Repro #19416 — live getElementsByTagName() NodeList foreach must advance. */
$d = new DOMDocument();
$d->loadXML('<r><a/><b/><c/></r>');
$list = $d->getElementsByTagName('*');
echo 'len=', $list->length, "\n";
$n = 0;
foreach ($list as $k => $node) {
    echo "k=$k name=", $node->nodeName, "\n";
    if (++$n > 8) {
        echo "INFINITE\n";
        break;
    }
}
echo "done n=$n\n";
