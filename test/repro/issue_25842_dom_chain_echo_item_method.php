<?php
/**
 * Issue #25842 — toplevel echo of getElementsByTagName()->item()->method() must not
 * observe null on item() (ARG_SEND / MethodCall-chain deferral vs 0/1-arg finals).
 */
$d = new DOMDocument();
$d->loadXML("<r>\n<a id=\"x\"/>\n</r>");
echo $d->getElementsByTagName('a')->item(0)->getLineNo(), "\n";
echo $d->getElementsByTagName('a')->item(0)->getAttribute('id'), "\n";
