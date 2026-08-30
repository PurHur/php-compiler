<?php
// AOT: iterator_to_array(SimpleXMLElement) leftover of Iterator host folds (#35852 / #35844).
$x = new SimpleXMLElement('<r><a>1</a><b>2</b></r>');
foreach (iterator_to_array($x) as $k => $v) {
    echo $k.'='.(string) $v."\n";
}
foreach (iterator_to_array($x, false) as $k => $v) {
    echo $k.'='.(string) $v."\n";
}
echo json_encode(iterator_to_array(new SimpleXMLElement('<r><a>1</a><b>2</b></r>')))."\n";
echo json_encode(iterator_to_array(new SimpleXMLElement('<r><a>1</a><b>2</b></r>'), false))."\n";
