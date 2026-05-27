<?php

declare(strict_types=1);

/**
 * PHPTypes smoke under Zend: Type constants + union/intersection fromTypeDecl (#2430).
 */

function types_unit_probe_vendor_autoload(): ?string
{
    $root = dirname(__DIR__, 3);
    if (!is_dir($root)) {
        return null;
    }
    $cwd = getcwd();
    if (false === $cwd) {
        $cwd = null;
    }
    chdir($root);
    if (!is_file('vendor/autoload.php')) {
        if (null !== $cwd) {
            chdir($cwd);
        }

        return null;
    }
    require_once 'vendor/autoload.php';
    if (null !== $cwd) {
        chdir($cwd);
    }

    return $root.'/vendor/autoload.php';
}

function types_unit_probe_types_smoke(): string
{
    if (null === types_unit_probe_vendor_autoload()) {
        return 'types_unit_probe types SKIP (no vendor)';
    }
    if (!class_exists(\PHPTypes\Type::class)) {
        return 'types_unit_probe types FAIL (no PHPTypes\\Type)';
    }

    foreach ([
        'TYPE_NULL',
        'TYPE_BOOLEAN',
        'TYPE_LONG',
        'TYPE_DOUBLE',
        'TYPE_STRING',
        'TYPE_OBJECT',
        'TYPE_ARRAY',
        'TYPE_CALLABLE',
        'TYPE_UNION',
        'TYPE_INTERSECTION',
    ] as $name) {
        if (!\defined(\PHPTypes\Type::class.'::'.$name)) {
            return 'types_unit_probe types FAIL (missing '.$name.')';
        }
    }

    $intLiteral = \PHPTypes\Type::fromTypeDecl(new \PHPCfg\Op\Type\Literal('int'));
    if (\PHPTypes\Type::TYPE_LONG !== $intLiteral->type) {
        return 'types_unit_probe types FAIL (int literal kind)';
    }

    if (!class_exists(\PHPCfg\Op\Type\Nullable::class)) {
        return 'types_unit_probe types FAIL (missing PHPCfg Nullable type)';
    }
    $nullableInt = \PHPTypes\Type::fromTypeDecl(
        new \PHPCfg\Op\Type\Nullable(new \PHPCfg\Op\Type\Literal('int'))
    );
    if (\PHPTypes\Type::TYPE_UNION !== $nullableInt->type) {
        return 'types_unit_probe types FAIL (nullable maps to union)';
    }

    if (class_exists(\PHPCfg\Op\Type\Intersection::class)) {
        $intersectionCfg = new \PHPCfg\Op\Type\Intersection([
            new \PHPCfg\Op\Type\Literal('A'),
            new \PHPCfg\Op\Type\Literal('B'),
        ]);
        $intersectionType = \PHPTypes\Type::fromTypeDecl($intersectionCfg);
        if (\PHPTypes\Type::TYPE_INTERSECTION !== $intersectionType->type) {
            return 'types_unit_probe types FAIL (intersection kind)';
        }
        if (2 !== \count($intersectionType->subTypes ?? [])) {
            return 'types_unit_probe types FAIL (intersection arity)';
        }
    }

    return 'types_unit_probe types OK';
}
