<?php
$d = new DOMDocument();
$d->loadXML('<r><a id="x" class="c">1</a><b y="2"/></r>');
$xp = new DOMXPath($d);
$n = $xp->query('//@*');
for ($i = 0; $i < $n->length; $i++) {
    echo $i, '=', $n->item($i)->nodeName, "\n";
}
