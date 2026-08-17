--TEST--
DOM Reflection C14N/lookupNamespaceURI/ctor stubs (#31849)
--FILE--
<?php
function dumpParam(string $cls, string $method, int $i): void
{
    $p = (new ReflectionMethod($cls, $method))->getParameters()[$i];
    $type = $p->hasType() ? (string) $p->getType() : 'none';
    $def = $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : 'n/a';
    echo $cls, '::', $method, ' arg', $i, '=', $type,
        ' opt=', ($p->isOptional() ? '1' : '0'),
        ' def=', $def, "\n";
}

dumpParam('DOMNode', 'C14N', 2);
dumpParam('DOMNode', 'C14N', 3);
dumpParam('DOMNode', 'C14NFile', 3);
dumpParam('DOMNode', 'C14NFile', 4);
dumpParam('DOMNode', 'lookupNamespaceURI', 0);
dumpParam('DOMDocument', '__construct', 0);
dumpParam('DOMDocument', '__construct', 1);
dumpParam('DOMElement', '__construct', 1);
dumpParam('DOMElement', '__construct', 2);
dumpParam('DOMText', '__construct', 0);
dumpParam('DOMComment', '__construct', 0);
dumpParam('DOMAttr', '__construct', 1);
dumpParam('DOMProcessingInstruction', '__construct', 1);

$doc = new DOMDocument();
$doc->loadXML('<r xmlns:p="urn:p"><p:a/></r>');
$el = $doc->documentElement->firstChild;
echo 'runtime_lookup_null=', var_export($el->lookupNamespaceURI(null), true), "\n";
echo 'runtime_c14n_null=', $el->C14N(false, false, null, null), "\n";
echo 'runtime_elem_ctor=', (new DOMElement('x'))->tagName, "\n";
echo 'runtime_text_ctor=', var_export((new DOMText())->data, true), "\n";
?>
--EXPECT--
DOMNode::C14N arg2=?array opt=1 def=NULL
DOMNode::C14N arg3=?array opt=1 def=NULL
DOMNode::C14NFile arg3=?array opt=1 def=NULL
DOMNode::C14NFile arg4=?array opt=1 def=NULL
DOMNode::lookupNamespaceURI arg0=?string opt=0 def=n/a
DOMDocument::__construct arg0=string opt=1 def='1.0'
DOMDocument::__construct arg1=string opt=1 def=''
DOMElement::__construct arg1=?string opt=1 def=NULL
DOMElement::__construct arg2=string opt=1 def=''
DOMText::__construct arg0=string opt=1 def=''
DOMComment::__construct arg0=string opt=1 def=''
DOMAttr::__construct arg1=string opt=1 def=''
DOMProcessingInstruction::__construct arg1=string opt=1 def=''
runtime_lookup_null=NULL
runtime_c14n_null=<p:a xmlns:p="urn:p"></p:a>
runtime_elem_ctor=x
runtime_text_ctor=''
