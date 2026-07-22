<?php
/** Repro #21930 — live DOMNodeList foreach + removeChild (Zend: seen=1,3 len=2). */
$d = new DOMDocument();
$d->loadXML('<r><a id="1"/><a id="2"/><a id="3"/></r>');
$list = $d->getElementsByTagName('a');
$seen = [];
foreach ($list as $node) {
    $seen[] = $node->getAttribute('id');
    if ($node->getAttribute('id') === '1') {
        $node->parentNode->removeChild($node);
    }
}
echo 'seen=' . implode(',', $seen) . ' len=' . $list->length . "\n";
