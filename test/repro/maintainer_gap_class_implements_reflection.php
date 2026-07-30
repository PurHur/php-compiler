<?php

declare(strict_types=1);

foreach (['class_implements', 'class_parents', 'class_uses'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
    foreach ($r->getParameters() as $p) {
        echo '  ', $p->getName(),
            ' type=', $p->hasType() ? (string) $p->getType() : '(none)',
            ' opt=', $p->isOptional() ? 'y' : 'n',
            ' def=', $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : 'N/A',
            "\n";
    }
}

$calls = 0;
spl_autoload_register(static function (string $class) use (&$calls): void {
    ++$calls;
});
echo 'missing=', var_export(class_implements('NoSuchClass25498'), true), ' calls=', $calls, "\n";
