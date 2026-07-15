<?php
// Repro #19295 — DOMCharacterData::$data write must update live text / parent nodeValue.
$dom = new DOMDocument();
$dom->loadXML("<root><item>hello</item></root>");
$item = $dom->documentElement->firstChild;
$text = $item->firstChild;
$text->data = "world";
echo "text=", $text->data, " nodeValue=", $item->nodeValue, " textContent=", $item->textContent, "\n";
