--TEST--
Language: ReflectionClass(Throwable)::getMethods match zend_exceptions.stub.php (#25427)
--FILE--
<?php
$r = new ReflectionClass(Throwable::class);
echo 'isInterface=', $r->isInterface() ? 'yes' : 'no', "\n";
echo 'ifaces=', implode(',', $r->getInterfaceNames()), "\n";
echo 'method_count=', count($r->getMethods()), "\n";
foreach ($r->getMethods() as $m) {
    echo $m->getName(), "\n";
}
?>
--EXPECT--
isInterface=yes
ifaces=Stringable
method_count=8
getMessage
getCode
getFile
getLine
getTrace
getPrevious
getTraceAsString
__toString
