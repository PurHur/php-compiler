--TEST--
SimpleXMLElement RecursiveIterator hasChildren/getChildren + RII (#21887, ext/simplexml/simplexml.c)
--FILE--
<?php
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
--EXPECT--
has_ri=Y
has_method=Y
before_valid=N has=N get=NULL
cur=a has=Y
ch=SimpleXMLElement:a
  gchild b=1
  gchild c=
---- RII ----
depth=0 a=a text=
depth=1 b=b text=1
depth=1 c=c text=
depth=0 a=a text=
depth=1 b=b text=2
depth=0 d=d text=x
attr_has=N attr_get=NULL
