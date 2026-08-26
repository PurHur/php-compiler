<?php
$d = new DOMDocument();
$d->loadXML('<r><![CDATA[hi]]></r>');
$r = $d->documentElement;
echo 'len='.$r->childNodes->length."\n";
$c = $r->firstChild;
echo 'name='.($c === null ? 'NULL' : $c->nodeName)."\n";
echo 'data='.($c === null ? 'NULL' : $c->data)."\n";
echo 'text='.$r->textContent."\n";
echo 'xml='.$d->saveXML($r)."\n";
