--TEST--
DOMCharacterData OOB DOMException message — Index Size Error (#22005, ext/dom/characterdata.c)
--FILE--
<?php
$d = new DOMDocument();
$t = $d->createTextNode('ab');
foreach (['substringData', 'deleteData', 'insertData', 'replaceData'] as $m) {
    try {
        if ($m === 'substringData' || $m === 'deleteData') {
            $t->$m(5, 1);
        } elseif ($m === 'insertData') {
            $t->$m(5, 'x');
        } else {
            $t->$m(5, 1, 'x');
        }
        echo "$m: no throw\n";
    } catch (DOMException $e) {
        echo "$m: ", $e->getMessage(), " code=", $e->getCode(), "\n";
    }
}
?>
--EXPECT--
substringData: Index Size Error code=1
deleteData: Index Size Error code=1
insertData: Index Size Error code=1
replaceData: Index Size Error code=1
