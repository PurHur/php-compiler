<?php
$x = new SimpleXMLElement("<root/>");
$c = $x->addChild("item", "hi");
$c["id"] = "1";
echo trim($x->asXML()), "\n";
$c["id"] = "2";
echo trim($x->asXML()), "\n";
$c["empty"] = "";
echo trim($x->asXML()), "\n";
unset($c["id"]);
echo trim($x->asXML()), "\n";
try {
    $c[""] = "x";
    echo "empty-name-ok\n";
} catch (ValueError $e) {
    echo "empty-name:", $e->getMessage(), "\n";
}
