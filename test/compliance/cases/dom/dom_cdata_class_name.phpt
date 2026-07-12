--TEST--
DOMCdataSection canonical get_class() name (#18136, ext/dom/cdatasection.c)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$section = $doc->createCDATASection('x');
echo get_class($section), "\n";
echo class_exists('DOMCdataSection', false) ? '1' : '0', "\n";
echo class_exists('DOMCDATASection', false) ? '1' : '0', "\n";
echo $section instanceof DOMCdataSection ? '1' : '0', "\n";
--EXPECT--
DOMCdataSection
1
1
1
