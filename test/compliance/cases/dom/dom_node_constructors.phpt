--TEST--
DOM leaf node public constructors (orphaned Text/Comment/CDATA/PI/EntityRef/Attr) (#24631)
--FILE--
<?php
echo (new DOMComment('hi'))->data, "\n";
echo (new DOMText('hi'))->data, "\n";
echo (new DOMCdataSection('x'))->data, "\n";
echo (new DOMProcessingInstruction('t', 'd'))->target, "\n";
echo (new DOMEntityReference('amp'))->nodeName, "\n";
echo (new DOMAttr('id', '1'))->value, "\n";
echo 'comment_empty=[', (new DOMComment())->data, "]\n";
echo 'text_empty=[', (new DOMText())->data, "]\n";
echo 'attr_empty=[', (new DOMAttr('id'))->value, "]\n";
echo 'pi_empty=[', (new DOMProcessingInstruction('t'))->data, "]\n";
try {
    new DOMCdataSection();
} catch (ArgumentCountError $ex) {
    echo "cdata_argc=ArgumentCountError\n";
}
try {
    new DOMEntityReference();
} catch (ArgumentCountError $ex) {
    echo "eref_argc=ArgumentCountError\n";
}
try {
    new DOMAttr();
} catch (ArgumentCountError $ex) {
    echo "attr_argc=ArgumentCountError\n";
}
$doc = new DOMDocument();
$doc->loadXML('<r/>');
$doc->documentElement->appendChild(new DOMComment('x'));
echo trim($doc->saveXML($doc->documentElement)), "\n";
$doc2 = new DOMDocument();
$doc2->appendChild($doc2->importNode(new DOMText('z'), true));
echo 'import_text=', $doc2->firstChild->data, "\n";
--EXPECT--
hi
hi
x
t
amp
1
comment_empty=[]
text_empty=[]
attr_empty=[]
pi_empty=[]
cdata_argc=ArgumentCountError
eref_argc=ArgumentCountError
attr_argc=ArgumentCountError
<r><!--x--></r>
import_text=z
