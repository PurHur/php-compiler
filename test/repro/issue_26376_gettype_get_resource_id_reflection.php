<?php
/**
 * #26376 — gettype / get_resource_id Reflection matches Zend stubs
 * (mixed $value): string and ($resource): int.
 */
foreach (['gettype', 'get_resource_id'] as $name) {
    $r = new ReflectionFunction($name);
    $p = $r->getParameters()[0];
    echo $name,
        ' param=', $p->getName(), ':', $p->getType() ? (string) $p->getType() : '<none>',
        ' return=', $r->getReturnType() ? (string) $r->getReturnType() : '<none>',
        "\n";
}
echo 'named_gettype=', gettype(value: 1), "\n";
$h = fopen('php://memory', 'r');
echo 'named_gri=', get_resource_id(resource: $h), "\n";
fclose($h);
