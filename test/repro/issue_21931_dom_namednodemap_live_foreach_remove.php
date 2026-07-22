<?php
/** Repro #21931 — live DOMNamedNodeMap foreach + removeAttribute (Zend: seen=a len=2). */
$d = new DOMDocument();
$d->loadXML('<r a="1" b="2" c="3"/>');
$m = $d->documentElement->attributes;
$seen = [];
foreach ($m as $attr) {
    $seen[] = $attr->name;
    if ($attr->name === 'a') {
        $d->documentElement->removeAttribute('a');
    }
}
echo 'seen=' . implode(',', $seen) . ' len=' . $m->length . "\n";
