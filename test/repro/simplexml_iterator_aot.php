<?php
// #35844 — AOT SimpleXMLElement Iterator leftover of hasChildren (#35827).
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
