--TEST--
Dom\HTMLElement selector Reflection arity/types (#28741, ext/dom/php_dom.stub.php)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsDomLivingStandardNamespace()) {
    die('skip Dom\\ living API requires PHP_COMPILER_PROFILE=8.4 (#28741)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$d = Dom\HTMLDocument::createFromString('<div><span id="s">t</span></div>', LIBXML_NOERROR);
$el = $d->documentElement;
$rc = new ReflectionClass(Dom\HTMLElement::class);
foreach (['querySelector', 'querySelectorAll', 'closest', 'matches', 'getElementsByTagName'] as $m) {
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
echo 'named_qs=', $el->querySelector(selectors: '#s')?->tagName ?? '(null)', "\n";
echo 'named_tag=', $el->getElementsByTagName(qualifiedName: 'span')->length, "\n";
echo 'pos_qs=', $el->querySelector('#s')?->tagName ?? '(null)', "\n";
$span = $el->querySelector('#s');
echo 'matches=', $span->matches(selectors: 'span') ? 'Y' : 'N', "\n";
echo 'closest=', $span->closest(selectors: 'div')?->tagName ?? '(null)', "\n";
echo 'named_qsa=', $el->querySelectorAll(selectors: 'span')->length, "\n";
--EXPECT--
querySelector arity=1 req=1 ret=?Dom\Element
  params=selectors:string:REQ
querySelectorAll arity=1 req=1 ret=Dom\NodeList
  params=selectors:string:REQ
closest arity=1 req=1 ret=?Dom\Element
  params=selectors:string:REQ
matches arity=1 req=1 ret=bool
  params=selectors:string:REQ
getElementsByTagName arity=1 req=1 ret=Dom\HTMLCollection
  params=qualifiedName:string:REQ
named_qs=SPAN
named_tag=1
pos_qs=SPAN
matches=Y
closest=DIV
named_qsa=1
