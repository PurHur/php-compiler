<?php

declare(strict_types=1);

if (!class_exists('EnumCases', false)) {
    echo "fail: EnumCases builtin attribute class missing under forward profile\n";
    exit(1);
}

if (!(new ReflectionClass('EnumCases'))->isInternal()) {
    echo "fail: EnumCases is not internal\n";
    exit(1);
}

enum Suit {
    #[EnumCases('red')]
    case Hearts;
}

$ref = new ReflectionEnumUnitCase(Suit::class, 'Hearts');
if (1 !== count($ref->getAttributes(EnumCases::class))) {
    echo "fail: expected one EnumCases attribute on enum case\n";
    exit(1);
}

if ('red' !== $ref->getAttributes(EnumCases::class)[0]->newInstance()->name) {
    echo "fail: EnumCases attribute name mismatch\n";
    exit(1);
}

echo "ok\n";
