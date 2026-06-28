<?php

declare(strict_types=1);

if (!class_exists('EnumCases', false)) {
    echo "fail: EnumCases class missing\n";
    exit(1);
}

enum PaymentMethod
{
    #[EnumCases('card')]
    case CreditCard;
    #[EnumCases('card')]
    case DebitCard;
}

$ref = new ReflectionEnumUnitCase(PaymentMethod::class, 'CreditCard');
$attrs = $ref->getAttributes(EnumCases::class);
if (1 !== count($attrs)) {
    echo 'fail: expected one EnumCases attribute, got ', count($attrs), "\n";
    exit(1);
}

$instance = $attrs[0]->newInstance();
if (!is_object($instance) || 'card' !== $instance->name) {
    echo 'fail: EnumCases attribute instance mismatch ', var_export($instance, true), "\n";
    exit(1);
}

echo "ok\n";
