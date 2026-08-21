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
