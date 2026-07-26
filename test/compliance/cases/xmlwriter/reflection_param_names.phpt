--TEST--
XMLWriter Reflection/named params match php-src stubs (#23407)
--FILE--
<?php
$rm = new ReflectionMethod('XMLWriter', 'setIndent');
echo 'setIndent=', $rm->getParameters()[0]->getName(), "\n";
$rm = new ReflectionMethod('XMLWriter', 'setIndentString');
echo 'setIndentString=', $rm->getParameters()[0]->getName(), "\n";
$rm = new ReflectionMethod('XMLWriter', 'flush');
echo 'flush=', implode(',', array_map(static fn ($p) => $p->getName(), $rm->getParameters())), "\n";
$rm = new ReflectionMethod('XMLWriter', 'outputMemory');
echo 'outputMemory=', implode(',', array_map(static fn ($p) => $p->getName(), $rm->getParameters())), "\n";
$rm = new ReflectionMethod('XMLWriter', 'writeAttributeNs');
echo 'writeAttributeNs=', implode(',', array_map(static fn ($p) => $p->getName(), $rm->getParameters())), "\n";
$rm = new ReflectionMethod('XMLWriter', 'startElementNs');
echo 'startElementNs=', implode(',', array_map(static fn ($p) => $p->getName(), $rm->getParameters())), "\n";
$rf = new ReflectionFunction('xmlwriter_set_indent');
echo 'proc=', implode(',', array_map(static fn ($p) => $p->getName(), $rf->getParameters())), "\n";
$w = new XMLWriter();
$w->openMemory();
var_export($w->setIndent(enable: true));
echo "\n";
try {
    $w->setIndent(indent: true);
    echo "legacy-ok\n";
} catch (Throwable $e) {
    echo "legacy-reject\n";
}
echo "ok\n";
--EXPECT--
setIndent=enable
setIndentString=indentation
flush=empty
outputMemory=flush
writeAttributeNs=prefix,name,namespace,value
startElementNs=prefix,name,namespace
proc=writer,enable
true
legacy-reject
ok
