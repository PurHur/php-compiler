<?php
declare(strict_types=1);

$d = new DOMDocument();
$d->loadXML('<a><b/><c/></a>');
$a = $d->documentElement;
$b = $a->firstChild;
$c = $a->lastChild;
$old = $a->replaceChild($c, $b);
echo 'replace-first=', $a->firstChild->nodeName, ' old=', $old->nodeName, "\n";

$d2 = new DOMDocument();
$d2->loadXML('<x/>');
$x = $d2->documentElement;
try {
    $a->replaceChild($x, $c);
    echo "wrong-doc-missed\n";
} catch (Exception $e) {
    echo 'wrong-doc=', $e->getMessage(), "\n";
}

$new = $d->createElement('d');
$a->insertBefore($new, $c);
echo 'insert-first=', $a->firstChild->nodeName, ' insert-last=', $a->lastChild->nodeName, "\n";

$removed = $a->removeChild($c);
echo 'removed=', $removed->nodeName, ' first=', $a->firstChild->nodeName, "\n";
