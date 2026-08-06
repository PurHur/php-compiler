--TEST--
Dom\HTMLDocument/XMLDocument::createFromFile Reflection arity/types (#27924, ext/dom/php_dom.stub.php)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsDomLivingStandardNamespace()) {
    die('skip Dom\\ living API requires PHP_COMPILER_PROFILE=8.4 (#27924)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

foreach ([Dom\HTMLDocument::class, Dom\XMLDocument::class] as $c) {
    $rf = new ReflectionMethod($c, 'createFromFile');
    $rt = $rf->getReturnType();
    echo $c, ' arity=', $rf->getNumberOfParameters(),
        ' req=', $rf->getNumberOfRequiredParameters(),
        ' ret=', $rt ? (string) $rt : '(none)', "\n";
    $parts = [];
    foreach ($rf->getParameters() as $p) {
        $parts[] = $p->getName()
            .':'
            .($p->hasType() ? (string) $p->getType() : '-')
            .':'.($p->isOptional() ? 'OPT' : 'REQ');
    }
    echo '  params=', implode(',', $parts), "\n";
}
--EXPECT--
Dom\HTMLDocument arity=3 req=1 ret=Dom\HTMLDocument
  params=path:string:REQ,options:int:OPT,overrideEncoding:?string:OPT
Dom\XMLDocument arity=3 req=1 ret=Dom\XMLDocument
  params=path:string:REQ,options:int:OPT,overrideEncoding:?string:OPT
