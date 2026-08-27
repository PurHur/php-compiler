<?php
/** #35421 — removeChild return cloneNode with siblings (LiveSlots restamp). */
$d = new DOMDocument();
$d->loadXML('<r><a/><b/></r>');
$a = $d->documentElement->firstChild;
$ret = $d->documentElement->removeChild($a);
echo 'clone=', $ret->cloneNode(false)->tagName, "\n";
