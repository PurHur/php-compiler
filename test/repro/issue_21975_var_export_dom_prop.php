<?php
declare(strict_types=1);
// #21975 — var_export($domProp, true) after @loadHTML must match Zend strings
$d = new DOMDocument();
@$d->loadHTML('<p>hello</p>');
$p = $d->getElementsByTagName('p')->item(0);
$tn = $p->firstChild;
echo 'nodeName=', var_export($p->nodeName, true), "\n";
echo 'data=', var_export($tn->data, true), "\n";
echo 'onearg=';
var_export($tn->data);
echo "\n";
$x = $tn->data;
echo 'assign=', var_export($x, true), "\n";
