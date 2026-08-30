<?php
$src = new DOMDocument();
$cd = $src->createCDATASection('x');
$pi = $src->createProcessingInstruction('pi', 'data');
$dst = new DOMDocument();
$n1 = $dst->importNode($cd, true);
$n2 = $dst->importNode($pi, true);
echo 'cd name=', $n1->nodeName, ' type=', $n1->nodeType, ' val=', $n1->nodeValue, "\n";
echo 'pi name=', $n2->nodeName, ' type=', $n2->nodeType, ' val=', $n2->nodeValue, "\n";
