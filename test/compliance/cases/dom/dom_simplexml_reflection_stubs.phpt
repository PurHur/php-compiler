--TEST--
DOM/SimpleXML Reflection stubs + children named args (#31887)
--FILE--
<?php
function dumpParam(string $cls, string $method, int $i): void
{
    $p = (new ReflectionMethod($cls, $method))->getParameters()[$i];
    $type = $p->hasType() ? (string) $p->getType() : 'none';
    $def = $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : 'n/a';
    echo $cls, '::', $method, ' arg', $i, ' name=', $p->getName(),
        ' type=', $type,
        ' opt=', ($p->isOptional() ? '1' : '0'),
        ' def=', $def, "\n";
}

dumpParam('DOMDocument', 'schemaValidate', 1);
dumpParam('DOMDocument', 'schemaValidateSource', 1);
dumpParam('DOMDocument', 'xinclude', 0);
dumpParam('DOMDocument', 'createElement', 1);
dumpParam('DOMDocument', 'createElementNS', 2);
dumpParam('DOMXPath', 'registerPhpFunctions', 0);
dumpParam('DOMImplementation', 'createDocument', 1);
$rm = new ReflectionMethod('DOMImplementation', 'createDocumentType');
echo 'createDocumentType req=', $rm->getNumberOfRequiredParameters(), '/', $rm->getNumberOfParameters(), "\n";
dumpParam('DOMImplementation', 'createDocumentType', 0);
dumpParam('DOMImplementation', 'createDocumentType', 1);
dumpParam('SimpleXMLElement', 'asXML', 0);
dumpParam('SimpleXMLElement', 'addChild', 1);
dumpParam('SimpleXMLElement', 'addChild', 2);
dumpParam('SimpleXMLElement', 'addAttribute', 2);
dumpParam('SimpleXMLElement', 'children', 0);
dumpParam('SimpleXMLElement', 'children', 1);
dumpParam('SimpleXMLElement', 'attributes', 0);
dumpParam('SimpleXMLElement', 'getDocNamespaces', 1);

$s = new SimpleXMLElement('<r xmlns:p="urn:p"><a>1</a><p:b>2</p:b></r>');
echo 'named_nsor=', $s->children(namespaceOrPrefix: 'p', isPrefix: true)->count(), "\n";
try {
    $s->children(ns: 'p', is_prefix: true);
    echo "legacy-ns-ok\n";
} catch (Throwable $e) {
    echo "legacy-ns-reject\n";
}

$impl = new DOMImplementation();
try {
    $impl->createDocumentType();
    echo "createDocumentType0-ok\n";
} catch (Throwable $e) {
    echo 'createDocumentType0=', get_class($e), "\n";
}
$el = (new DOMDocument())->createElement('x');
echo 'createElement_value=', var_export($el->nodeValue, true), "\n";
?>
--EXPECT--
DOMDocument::schemaValidate arg1 name=flags type=int opt=1 def=0
DOMDocument::schemaValidateSource arg1 name=flags type=int opt=1 def=0
DOMDocument::xinclude arg0 name=options type=int opt=1 def=0
DOMDocument::createElement arg1 name=value type=string opt=1 def=''
DOMDocument::createElementNS arg2 name=value type=string opt=1 def=''
DOMXPath::registerPhpFunctions arg0 name=restrict type=array|string|null opt=1 def=NULL
DOMImplementation::createDocument arg1 name=qualifiedName type=string opt=1 def=''
createDocumentType req=1/3
DOMImplementation::createDocumentType arg0 name=qualifiedName type=string opt=0 def=n/a
DOMImplementation::createDocumentType arg1 name=publicId type=string opt=1 def=''
SimpleXMLElement::asXML arg0 name=filename type=?string opt=1 def=NULL
SimpleXMLElement::addChild arg1 name=value type=?string opt=1 def=NULL
SimpleXMLElement::addChild arg2 name=namespace type=?string opt=1 def=NULL
SimpleXMLElement::addAttribute arg2 name=namespace type=?string opt=1 def=NULL
SimpleXMLElement::children arg0 name=namespaceOrPrefix type=?string opt=1 def=NULL
SimpleXMLElement::children arg1 name=isPrefix type=bool opt=1 def=false
SimpleXMLElement::attributes arg0 name=namespaceOrPrefix type=?string opt=1 def=NULL
SimpleXMLElement::getDocNamespaces arg1 name=fromRoot type=bool opt=1 def=true
named_nsor=1
legacy-ns-reject
createDocumentType0=ArgumentCountError
createElement_value=''
