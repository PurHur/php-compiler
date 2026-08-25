--TEST--
AOT: held getElementsByTagName item() after middle removeChild (#34646)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/><b/><c/></r>');
$list = $d->getElementsByTagName('*');
echo 'before=', $list->length, "\n";
$d->documentElement->removeChild($d->documentElement->childNodes->item(1));
echo 'after=', $list->length, "\n";
for ($i = 0; $i < $list->length; $i++) {
    echo $list->item($i)->nodeName, ',';
}
echo "\n";
?>
--EXPECT--
before=4
after=3
r,a,c,
