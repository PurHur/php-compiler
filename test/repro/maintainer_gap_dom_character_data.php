<?php
declare(strict_types=1);

$doc = new DOMDocument();
$t = $doc->createTextNode('abc');
$t->appendData('def');
echo $t->data, "\n";

$t->deleteData(1, 2);
echo $t->data, "\n";

$t->insertData(1, 'X');
echo $t->data, "\n";

$t->replaceData(1, 1, 'Y');
echo $t->data, "\n";

echo $t->substringData(0, 2), "\n";
