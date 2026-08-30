<?php
$x = new SimpleXMLElement('<root><a><b/></a></root>');
echo json_encode($x->getChildren());
echo "\n";
$x->rewind();
echo json_encode($x->hasChildren());
echo "\n";
echo json_encode($x->valid());
echo "\n";
echo json_encode($x->key());
echo "\n";
echo $x->getChildren()->getName();
echo "\n";
