--TEST--
DOMDocument/DOMNode __phpcDom* engine props are PHP-invisible (#31439)
--FILE--
<?php
error_reporting(E_ALL);
$d = new DOMDocument();
$d->loadXML('<r id="x"><a/></r>');
$el = $d->documentElement;
$el->setIdAttribute('id', true);
$rc = new ReflectionClass(DOMDocument::class);
echo 'hasMap=', $rc->hasProperty('__phpcDomElementIdMap') ? '1' : '0', "\n";
echo 'hasReg=', $rc->hasProperty('__phpcDomRegistryId') ? '1' : '0', "\n";
$ro = new ReflectionObject($d);
$leaks = [];
foreach ($ro->getProperties() as $p) {
    if (str_starts_with($p->getName(), '__phpc')) {
        $leaks[] = $p->getName();
    }
}
echo 'objLeaks=', implode(',', $leaks), "\n";
echo 'issetReg=', isset($d->__phpcDomRegistryId) ? '1' : '0', "\n";
echo 'issetElReg=', isset($el->__phpcDomRegistryId) ? '1' : '0', "\n";
set_error_handler(static function (int $no, string $msg): bool {
    if (str_contains($msg, '__phpcDomRegistryId')) {
        echo "W:undefined-reg\n";
    }
    return true;
});
$v = $d->__phpcDomRegistryId;
echo 'readReg=', gettype($v), "\n";
// Engine storage still drives getElementById.
$byId = $d->getElementById('x');
echo 'byId=', ($byId instanceof DOMElement && $byId->tagName === 'r') ? 'ok' : 'fail', "\n";
?>
--EXPECT--
hasMap=0
hasReg=0
objLeaks=
issetReg=0
issetElReg=0
W:undefined-reg
readReg=NULL
byId=ok
