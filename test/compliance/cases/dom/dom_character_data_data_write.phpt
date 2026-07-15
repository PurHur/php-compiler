--TEST--
stdlib DOMCharacterData::$data write live sync (#19295, ext/dom/characterdata.c)
--FILE--
<?php
$dom = new DOMDocument();
$dom->loadXML("<root><item>hello</item></root>");
$item = $dom->documentElement->firstChild;
$text = $item->firstChild;
$text->data = "world";
echo "text=", $text->data, " nodeValue=", $item->nodeValue, " textContent=", $item->textContent, "\n";
$text->nodeValue = "viaNV";
echo "nv=", $text->data, " parent=", $item->nodeValue, "\n";
$comment = $dom->createComment("old");
$comment->data = "new";
echo "comment=", $comment->data, " length=", $comment->length, "\n";
?>
--EXPECT--
text=world nodeValue=world textContent=world
nv=viaNV parent=viaNV
comment=new length=3
