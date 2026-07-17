<?php
// #19386 — XMLWriter comment + DTD streaming (AOT-safe; method_exists covered on VM/JIT)
$w = new XMLWriter();
$w->openMemory();
$w->startComment();
$w->text("c");
$w->endComment();
echo "comment=", $w->outputMemory(), "\n";
$w2 = new XMLWriter();
$w2->openMemory();
$w2->startDtd("r");
$w2->endDtd();
$w2->startElement("r");
$w2->endElement();
echo "dtd=", $w2->outputMemory(), "\n";
$w3 = new XMLWriter();
$w3->openMemory();
$w3->writeDtd("html", "-//W3C//DTD XHTML 1.0 Transitional//EN", "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd");
echo "writedtd=", $w3->outputMemory(), "\n";
$w4 = new XMLWriter();
$w4->openMemory();
$w4->writeDtd("r", null, null, "<!ELEMENT r EMPTY>");
echo "subset=", $w4->outputMemory(), "\n";
