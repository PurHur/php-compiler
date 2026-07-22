<?php

namespace Foo;

class C {}

$r = new \ReflectionClass(C::class);
echo $r->getNamespaceName(), '|', $r->inNamespace() ? 'y' : 'n', '|', $r->getShortName(), "\n";
$r2 = new \ReflectionClass(\stdClass::class);
echo $r2->getNamespaceName(), '|', $r2->inNamespace() ? 'y' : 'n', '|', $r2->getShortName(), "\n";
