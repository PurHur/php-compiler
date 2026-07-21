<?php
/** Repro #21887 — SimpleXMLElement RecursiveIterator hasChildren/getChildren + RII. */
error_reporting(E_ALL);
$sx = simplexml_load_string('<r><a><b>1</b><c/></a><a><b>2</b></a><d>x</d></r>');
echo 'has_ri=', in_array('RecursiveIterator', class_implements($sx), true) ? 'Y' : 'N', "\n";
echo 'has_method=', method_exists($sx, 'hasChildren') ? 'Y' : 'N', "\n";
echo 'before_valid=', $sx->valid() ? 'Y' : 'N', ' has=', $sx->hasChildren() ? 'Y' : 'N', ' get=', var_export($sx->getChildren(), true), "\n";
$sx->rewind();
echo 'cur=', $sx->current()->getName(), ' has=', $sx->hasChildren() ? 'Y' : 'N', "\n";
$ch = $sx->getChildren();
echo 'ch=', get_class($ch), ':', $ch->getName(), "\n";
$ch->rewind();
while ($ch->valid()) {
    echo '  gchild ', $ch->key(), '=', (string) $ch->current(), "\n";
    $ch->next();
}
echo "---- RII ----\n";
$it = new RecursiveIteratorIterator($sx, RecursiveIteratorIterator::SELF_FIRST);
foreach ($it as $k => $v) {
    echo 'depth=', $it->getDepth(), ' ', $k, '=', $v->getName(), ' text=', trim((string) $v), "\n";
}
$e = simplexml_load_string('<r a="1"><c/></r>');
$av = $e->attributes();
$av->rewind();
echo 'attr_has=', $av->hasChildren() ? 'Y' : 'N', ' attr_get=', var_export($av->getChildren(), true), "\n";
