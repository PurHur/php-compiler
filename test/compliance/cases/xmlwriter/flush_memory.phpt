--TEST--
xmlwriter XMLWriter::flush memory returns string — php-src php_xmlwriter_flush (#19385)
--FILE--
<?php
$w = new XMLWriter();
$w->openMemory();
$w->startElement('a');
$w->endElement();
$out = $w->flush(true);
echo 'type=', gettype($out), "\n";
var_export($out);
echo "\n";
$empty = $w->flush(true);
echo 'empty_type=', gettype($empty), "\n";
var_export($empty);
echo "\n";

$w2 = new XMLWriter();
$w2->openMemory();
$w2->startElement('b');
$w2->endElement();
$keep = $w2->flush(false);
echo 'keep=', var_export($keep, true), "\n";
echo 'again=', var_export($w2->outputMemory(false), true), "\n";
?>
--EXPECT--
type=string
'<a/>'
empty_type=string
''
keep='<b/>'
again='<b/>'
