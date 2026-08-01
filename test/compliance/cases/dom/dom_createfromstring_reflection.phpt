--TEST--
Dom\HTMLDocument/XMLDocument::createFromString Reflection arity/types (#26080, ext/dom/php_dom.stub.php)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsDomLivingStandardNamespace()) {
    die('skip Dom\\ living API requires PHP_COMPILER_PROFILE=8.4 (#26080)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

foreach ([Dom\HTMLDocument::class, Dom\XMLDocument::class] as $c) {
    $rf = new ReflectionMethod($c, 'createFromString');
    echo $c, ' arity=', $rf->getNumberOfParameters(),
        ' req=', $rf->getNumberOfRequiredParameters(), "\n";
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
Dom\HTMLDocument arity=3 req=1
  params=source:string:REQ,options:int:OPT,overrideEncoding:?string:OPT
Dom\XMLDocument arity=3 req=1
  params=source:string:REQ,options:int:OPT,overrideEncoding:?string:OPT
