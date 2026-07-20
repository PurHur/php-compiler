<?php
declare(strict_types=1);

/** Repro #21283 — DOM Reflection method names must keep Zend camelCase. */
$ref = new ReflectionClass('DOMElement');
echo $ref->getMethod('appendChild')->getName(), PHP_EOL;
echo $ref->getMethod('appendchild')->getName(), PHP_EOL;
$exact = false;
foreach ($ref->getMethods() as $m) {
    if ($m->getName() === 'appendChild') {
        $exact = true;
        break;
    }
}
echo $exact ? "exact_ok\n" : "exact_missing\n";
