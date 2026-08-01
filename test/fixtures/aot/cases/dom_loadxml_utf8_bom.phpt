--TEST--
AOT: DOMDocument::loadXML leading UTF-8 BOM (#26565)
--FILE--
<?php
$d = new DOMDocument();
echo @$d->loadXML("\xEF\xBB\xBF<root/>") ? "1" : "0", "\n";
echo $d->documentElement->tagName, "\n";
--EXPECT--
1
root
