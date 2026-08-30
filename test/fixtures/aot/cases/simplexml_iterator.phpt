--TEST--
AOT: SimpleXMLElement Iterator leftover of hasChildren matches Zend (ext/simplexml/sxe.c)
--FILE--
<?php
$x = new SimpleXMLElement('<root><a><b/></a></root>');
echo 'fresh_hc=', json_encode($x->hasChildren()), "\n";
echo 'fresh_gc=', json_encode($x->getChildren()), "\n";
$x->rewind();
echo 'hc=', json_encode($x->hasChildren()), "\n";
echo 'valid=', json_encode($x->valid()), "\n";
echo 'key=', json_encode($x->key()), "\n";
$c = $x->getChildren();
echo 'gc=', (null === $c ? 'null' : $c->getName()), "\n";
$x->next();
echo 'after_next_valid=', json_encode($x->valid()), "\n";
--EXPECT--
fresh_hc=false
fresh_gc=null
hc=true
valid=true
key="a"
gc=a
after_next_valid=false
