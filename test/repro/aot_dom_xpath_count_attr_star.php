<?php
$d = new DOMDocument();
$d->loadXML('<r><a id="x" class="c">1</a><b y="2"/></r>');
$xp = new DOMXPath($d);
echo 'count=', $xp->evaluate('count(//@*)'), "\n";
