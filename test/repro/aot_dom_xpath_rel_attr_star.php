<?php
$d = new DOMDocument();
$d->loadXML('<r><a id="x" class="c">1</a><b y="2"/></r>');
$xp = new DOMXPath($d);
$a = $xp->query('//a')->item(0);
echo 'rel_star=', $xp->query('@*', $a)->length, "\n";
