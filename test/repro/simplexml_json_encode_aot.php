<?php
echo json_encode(new SimpleXMLElement('<r><a>1</a></r>'));
echo "\n";
$x = new SimpleXMLElement('<root id="1"><a>x</a><a>y</a></root>');
echo json_encode($x);
echo "\n";
