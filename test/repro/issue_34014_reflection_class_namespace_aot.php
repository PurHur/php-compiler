<?php
namespace Foo\Bar;

class T
{
}

$r = new \ReflectionClass(T::class);
echo $r->getNamespaceName(), PHP_EOL;
echo $r->inNamespace() ? '1' : '0', PHP_EOL;
echo $r->getShortName(), PHP_EOL;

$g = new \ReflectionClass(\stdClass::class);
echo $g->getNamespaceName() === '' ? 'empty' : $g->getNamespaceName(), PHP_EOL;
echo $g->inNamespace() ? '1' : '0', PHP_EOL;
