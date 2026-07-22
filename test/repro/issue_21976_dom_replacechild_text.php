<?php
/**
 * Repro #21976 — replaceChild(createTextNode, existing text child) must not throw
 * Hierarchy request error (php-src ext/dom/node.c dom_node_replace_child).
 */
$d = new DOMDocument();
@$d->loadHTML('<p>abcdef</p>');
$p = $d->getElementsByTagName('p')->item(0);
$old = $p->firstChild;
$new = $d->createTextNode('ZZ');
echo 'before=', $p->textContent, "\n";
try {
    $p->replaceChild($new, $old);
    echo 'after=', $p->textContent, "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

// Workaround path (must remain green).
$d2 = new DOMDocument();
@$d2->loadHTML('<p>abcdef</p>');
$p2 = $d2->getElementsByTagName('p')->item(0);
$old2 = $p2->firstChild;
$new2 = $d2->createTextNode('YY');
$p2->removeChild($old2);
$p2->appendChild($new2);
echo 'workaround=', $p2->textContent, "\n";

// Invalid: Attr is not a tree child (#21976 done-when).
$d3 = new DOMDocument();
@$d3->loadHTML('<p>x</p>');
$p3 = $d3->getElementsByTagName('p')->item(0);
try {
    $p3->replaceChild($d3->createAttribute('x'), $p3->firstChild);
    echo "attr unexpected_ok\n";
} catch (Throwable $e) {
    echo 'attr ', get_class($e), "\n";
}
