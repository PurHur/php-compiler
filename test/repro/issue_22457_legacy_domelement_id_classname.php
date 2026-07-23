<?php
/**
 * Repro for #22457 — legacy DOMElement::$id / $className virtual props (PROFILE=8.4).
 */
$d = new DOMDocument();
$d->loadHTML("<html><body><a id=\"i\" class=\"x y\">t</a></body></html>", LIBXML_NOERROR);
$a = $d->getElementsByTagName("a")->item(0);
echo "id_get="; var_export($a->id); echo "\n";
echo "className_get="; var_export($a->className); echo "\n";
$a->id = "j";
$a->className = "p q";
echo "id_attr=" . $a->getAttribute("id") . "\n";
echo "class_attr=" . $a->getAttribute("class") . "\n";
