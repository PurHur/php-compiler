--TEST--
DOMElement::setAttribute/setAttributeNS empty QName → ValueError (#24480, ext/dom/element.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<r/>');
$el = $doc->documentElement;
foreach ([null, ''] as $i => $name) {
    try {
        $el->setAttribute($name, 'x');
        echo "set$i=ok\n";
    } catch (Throwable $e) {
        echo 'set'.$i.'='.get_class($e).':'.$e->getMessage()."\n";
    }
}
try {
    $el->setAttributeNS(null, '', 'x');
    echo "setNS=ok\n";
} catch (Throwable $e) {
    echo 'setNS='.get_class($e).':'.$e->getMessage()."\n";
}
echo 'attrs='.$el->attributes->length."\n";
?>
--EXPECT--
set0=ValueError:DOMElement::setAttribute(): Argument #1 ($qualifiedName) cannot be empty
set1=ValueError:DOMElement::setAttribute(): Argument #1 ($qualifiedName) cannot be empty
setNS=ValueError:DOMElement::setAttributeNS(): Argument #2 ($qualifiedName) cannot be empty
attrs=0
