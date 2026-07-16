--TEST--
AOT: XMLWriter::flush() memory returns string (#19385, ext/xmlwriter/php_xmlwriter.c)
--FILE--
<?php
declare(strict_types=1);

$w = new XMLWriter();
$w->openMemory();
$w->startElement('a');
$w->endElement();
// Default $empty=true (bool literal `true` is Instruction-backed in user-script AOT).
$out = $w->flush();
echo 'type=', gettype($out), "\n";
echo $out, "\n";
--EXPECT--
type=string
<a/>
--EXPECT_EXIT--
0
