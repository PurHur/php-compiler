<?php
// Part of #36204 — Dom NodeList dimension + ArrayObject ARRAY_AS_PROPS via Module hooks.
// @differential-skip-aot: DOMNodeList has_dimension/read_dimension not AOT-lowered yet (pre-existing; VM path owns ObjectDimensionHandler)
$doc = new DOMDocument();
$doc->loadXML('<r><a/><a/></r>');
$list = $doc->getElementsByTagName('a');
echo isset($list[0]) ? '1' : '0';
echo isset($list[9]) ? '1' : '0';
echo $list[0]->tagName, "\n";

$ao = new ArrayObject(['x' => 1, 'y' => 2], ArrayObject::ARRAY_AS_PROPS);
echo $ao->x, $ao['y'], "\n";
$ao->z = 3;
echo $ao->z, count((array) $ao), "\n";
