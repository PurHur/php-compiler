<?php
$d = new DOMDocument();
$d->loadXML('<r><a id="x" class="c">1</a><b y="2"/></r>');
$xp = new DOMXPath($d);
$n = $xp->query('//@*');
echo 'len=', $n->length, "\n";
echo 'name=', $n->item(0)->nodeName, "\n";
