--TEST--
xmlwriter startComment/endComment/startDtd/endDtd/writeDtd — (#19386, ext/xmlwriter/php_xmlwriter.c)
--FILE--
<?php
$w = new XMLWriter();
$w->openMemory();
foreach (['startComment', 'endComment', 'startDtd', 'endDtd', 'writeDtd'] as $m) {
    echo $m, '=', method_exists($w, $m) ? '1' : '0', "\n";
}
var_export($w->startComment());
echo "\n";
var_export($w->text('c'));
echo "\n";
var_export($w->endComment());
echo "\n";
echo 'comment=', $w->outputMemory(), "\n";

$w2 = new XMLWriter();
$w2->openMemory();
var_export($w2->startDtd('r'));
echo "\n";
var_export($w2->endDtd());
echo "\n";
$w2->startElement('r');
$w2->endElement();
echo 'dtd=', $w2->outputMemory(), "\n";

$w3 = new XMLWriter();
$w3->openMemory();
var_export($w3->writeDtd('html', '-//W3C//DTD XHTML 1.0 Transitional//EN', 'http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd'));
echo "\n";
echo 'writedtd=', $w3->outputMemory(), "\n";

$w4 = new XMLWriter();
$w4->openMemory();
var_export($w4->writeDtd('r', null, null, '<!ELEMENT r EMPTY>'));
echo "\n";
echo 'subset=', $w4->outputMemory(), "\n";
?>
--EXPECT--
startComment=1
endComment=1
startDtd=1
endDtd=1
writeDtd=1
true
true
true
comment=<!--c-->
true
true
dtd=<!DOCTYPE r><r/>
true
writedtd=<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
true
subset=<!DOCTYPE r [<!ELEMENT r EMPTY>]>
