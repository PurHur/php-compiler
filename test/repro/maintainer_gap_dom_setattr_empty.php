<?php
/** Repro #24480 — empty/null setAttribute QName → ValueError (php-src ext/dom/element.c). */
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
// No empty-named Attr must remain.
echo 'attrs='.$el->attributes->length."\n";
