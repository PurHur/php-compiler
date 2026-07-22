--TEST--
DOMNode::replaceChild(createTextNode, textChild) updates textContent (#21976, ext/dom/node.c)
--FILE--
<?php
$d = new DOMDocument();
@$d->loadHTML('<p>abcdef</p>');
$p = $d->getElementsByTagName('p')->item(0);
$old = $p->firstChild;
$new = $d->createTextNode('ZZ');
echo 'before=', $p->textContent, "\n";
$p->replaceChild($new, $old);
echo 'after=', $p->textContent, "\n";
echo 'old_parent=', ($old->parentNode === null ? 'null' : $old->parentNode->nodeName), "\n";

$d2 = new DOMDocument();
@$d2->loadHTML('<p>abcdef</p>');
$p2 = $d2->getElementsByTagName('p')->item(0);
$c = $d2->createComment('c');
$p2->replaceChild($c, $p2->firstChild);
echo 'comment_len=', $p2->childNodes->length, ' tc=', $p2->textContent, "\n";

$d3 = new DOMDocument();
@$d3->loadHTML('<p>x</p>');
$p3 = $d3->getElementsByTagName('p')->item(0);
try {
    $p3->replaceChild($d3->createAttribute('x'), $p3->firstChild);
    echo "attr unexpected_ok\n";
} catch (DOMException $e) {
    echo 'attr ', $e->getMessage(), "\n";
}
?>
--EXPECT--
before=abcdef
after=ZZ
old_parent=null
comment_len=1 tc=
attr Hierarchy Request Error
