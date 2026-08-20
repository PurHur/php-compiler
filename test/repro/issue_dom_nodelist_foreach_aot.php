<?php
/**
 * AOT: foreach over DOMNodeList must iterate live items (ext/dom/nodelist.c).
 */
$doc = new DOMDocument();
$doc->loadXML('<r><x/><x/><x/></r>');
$list = $doc->getElementsByTagName('x');
echo "count: " . $list->length . "\n";
foreach ($list as $node) {
    echo $node->nodeName . "\n";
}
echo "DONE\n";
