--TEST--
Dom\HTMLDocument/XMLDocument instance method Reflection arity/types (#28740, ext/dom/php_dom.stub.php)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsDomLivingStandardNamespace()) {
    die('skip Dom\\ living API requires PHP_COMPILER_PROFILE=8.4 (#28740)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$html = Dom\HTMLDocument::createFromString('<p id="a">x</p>', LIBXML_NOERROR);
$rc = new ReflectionClass(Dom\HTMLDocument::class);
foreach (['getElementById', 'saveHtml', 'saveXml'] as $m) {
    $rm = $rc->getMethod($m);
    $rt = $rm->getReturnType();
    echo $m, ' arity=', $rm->getNumberOfParameters(),
        ' req=', $rm->getNumberOfRequiredParameters(),
        ' ret=', $rt ? (string) $rt : '(none)', "\n";
    $parts = [];
    foreach ($rm->getParameters() as $p) {
        $parts[] = $p->getName()
            .':'
            .($p->hasType() ? (string) $p->getType() : '-')
            .':'.($p->isOptional() ? 'OPT' : 'REQ');
    }
    echo '  params=', implode(',', $parts), "\n";
}
$el = $html->getElementById(elementId: 'a');
echo 'named_id=', $el?->tagName ?? '(null)', "\n";
echo 'named_html=', strlen($html->saveHtml(node: $el)) > 0 ? 'ok' : 'empty', "\n";
$xmlFrag = $html->saveXml(node: $el, options: 0);
echo 'named_xml=', is_string($xmlFrag) && strlen($xmlFrag) > 0 ? 'ok' : 'bad', "\n";

$x = Dom\XMLDocument::createFromString('<?xml version="1.0"?><root xml:id="a"/>');
$rcx = new ReflectionClass(Dom\XMLDocument::class);
foreach (['getElementById', 'saveXml'] as $m) {
    $rm = $rcx->getMethod($m);
    $rt = $rm->getReturnType();
    echo 'XML ', $m, ' arity=', $rm->getNumberOfParameters(),
        ' ret=', $rt ? (string) $rt : '(none)', "\n";
}
echo 'xml_named_id=', $x->getElementById(elementId: 'a')?->nodeName ?? '(null)', "\n";
--EXPECT--
getElementById arity=1 req=1 ret=?Dom\Element
  params=elementId:string:REQ
saveHtml arity=1 req=0 ret=string
  params=node:?Dom\Node:OPT
saveXml arity=2 req=0 ret=string|false
  params=node:?Dom\Node:OPT,options:int:OPT
named_id=P
named_html=ok
named_xml=ok
XML getElementById arity=1 ret=?Dom\Element
XML saveXml arity=2 ret=string|false
xml_named_id=root
