<?php
$d = new DOMDocument();
$d->loadXML("<r><a/><b/></r>");
$r = $d->documentElement;
$a = $r->firstChild;
$b = $a->nextSibling;
$r->textContent = "z";
echo "a_parent_null=", ($a->parentNode === null ? "true" : "false"), "\n";
echo "b_parent_null=", ($b->parentNode === null ? "true" : "false"), "\n";
echo "kids=", $r->childNodes->length, "\n";
echo "text=", $r->textContent, "\n";
echo "xml=", trim($d->saveXML($r)), "\n";
