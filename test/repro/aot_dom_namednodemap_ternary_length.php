<?php
// AOT: ternary on DOMNamedNodeMap::$length must compile and match VM (#35554).
$d = new DOMDocument();
$d->loadXML('<root id="r"><child>a</child></root>');
$attrs = $d->documentElement->attributes;
echo 'attrs_len=', ($attrs ? $attrs->length : -1), "\n";
