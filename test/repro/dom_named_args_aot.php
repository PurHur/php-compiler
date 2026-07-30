<?php
$d = new DOMDocument();
$d->loadXML("<r/>");
echo "saveXML_opts=", (str_contains($d->saveXML(options: 0), "<r") ? "ok" : "bad"), "\n";
echo "saveXML_null=", (str_contains($d->saveXML(node: null), "<r") ? "ok" : "bad"), "\n";
echo "saveHTML=", (is_string($d->saveHTML(node: null)) ? "ok" : "bad"), "\n";
echo "loadXML=", ($d->loadXML(source: "<r><a/></r>") ? "ok" : "bad"), "\n";
echo "ce=", $d->createElement(localName: "x")->tagName, "\n";
