--TEST--
AOT: held getElementsByTagName item() after childNodes fetch (#34646)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/><b/><c/></r>');
$list = $d->getElementsByTagName('*');
$_ = $d->documentElement->childNodes->item(1);
echo 'len=', $list->length, "\n";
for ($i = 0; $i < $list->length; $i++) {
    echo $list->item($i)->nodeName, ',';
}
echo "\n";
?>
--EXPECT--
len=4
r,a,b,c,
