<?php
$w = new XMLWriter();
$w->openMemory();
$w->startElement("r");
$w->startCdata();
$w->text("a&b<>");
$w->endCdata();
$w->endElement();
echo $w->outputMemory(), "\n";
$w2 = new XMLWriter();
$w2->openMemory();
$w2->startPi("xml-stylesheet");
$w2->text("type=\"text/xsl\" href=\"style.xsl\"");
$w2->endPi();
echo $w2->outputMemory(), "\n";
