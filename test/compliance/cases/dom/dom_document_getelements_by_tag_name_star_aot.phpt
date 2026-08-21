--TEST--
DOMDocument::getElementsByTagName("*") length + item() under AOT (#33063, ext/dom/php_dom.c)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/><b/><c/></r>');
$list = $d->getElementsByTagName('*');
echo 'len=', $list->length, "\n";
for ($i = 0; $i < $list->length; $i++) {
    echo $i, '=', $list->item($i)->tagName, "\n";
}
$named = $d->getElementsByTagName('a');
echo 'a=', $named->length, "\n";
--EXPECT--
len=4
0=r
1=a
2=b
3=c
a=1
