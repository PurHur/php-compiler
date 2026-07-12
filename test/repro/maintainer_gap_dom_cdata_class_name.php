<?php

declare(strict_types=1);

$doc = new DOMDocument();
$section = $doc->createCDATASection('x');
echo get_class($section), "\n";
echo class_exists('DOMCdataSection', false) ? '1' : '0', "\n";
echo $section instanceof DOMCdataSection ? '1' : '0', "\n";
