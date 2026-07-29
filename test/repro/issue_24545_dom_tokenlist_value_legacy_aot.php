<?php
$dom = new DOMDocument();
$dom->loadXML("<root><e class=\"a b\"/></root>");
$cl = $dom->documentElement->firstChild->classList;
echo "class=", get_class($cl), "\n";
echo "before=", $cl->value, "\n";
$cl->value = "c d";
echo "after=", $cl->value, "\n";
echo "attr=", $dom->documentElement->firstChild->getAttribute("class"), "\n";
echo "cast=", (string)$cl, "\n";
