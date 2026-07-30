<?php
declare(strict_types=1);

$r = new ReflectionClass(Throwable::class);
echo 'isInterface=', $r->isInterface() ? 'yes' : 'no', "\n";
echo 'method_count=', count($r->getMethods()), "\n";
foreach ($r->getMethods() as $m) {
    $rt = $m->getReturnType();
    echo $m->getName(),
        ' decl=', $m->getDeclaringClass()->getName(),
        ' return=', $rt ? (string) $rt : '-',
        "\n";
}
