<?php
/**
 * DOM sibling shape from #25525: nullsafe + ?? then later property fetch.
 */
$doc = new DOMDocument();
$doc->loadXML('<a><b/></a>');
$b = $doc->documentElement->firstChild;
$type = $b->firstChild?->nodeType ?? "null";
echo "type=$type\n";
echo "name=".$b->nodeName."\n";
echo "done\n";
