<?php
// #25327 — ReflectionClass::getInterfaces()/getInterfaceNames() order for ArrayObject
$r = new ReflectionClass('ArrayObject');
echo implode(',', array_keys($r->getInterfaces())), "\n";
echo implode(',', $r->getInterfaceNames()), "\n";
