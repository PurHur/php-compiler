<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\Compiler\AttributeEntry;
use PHPCompiler\JIT\Context;

/** Emit native attribute name tables into __init__ for JIT/AOT reflection (#1936, #4598). */
final class AttributeRegistry
{
    private static ?string $pendingClassLc = null;
    private static ?string $pendingMethodLc = null;
    /** @var list<string> */
    private static array $pendingNames = [];
    /** @var list<AttributeEntry> */
    private static array $pendingEntries = [];

    public static function registerDeclarations(Context $context): void
    {
        $void = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $context->getTypeFromString('int8**');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');

        if (null === $context->module->getNamedFunction('phpc_attr_register_class_attrs')) {
            $ft = $context->context->functionType($void, false, $i8p, $i8pp, $sizeT);
            $fn = $context->module->addFunction('phpc_attr_register_class_attrs', $ft);
            $context->registerFunction('phpc_attr_register_class_attrs', $fn);
        } else {
            $context->registerFunction(
                'phpc_attr_register_class_attrs',
                $context->module->getNamedFunction('phpc_attr_register_class_attrs')
            );
        }
        if (null === $context->module->getNamedFunction('phpc_attr_register_method_attrs')) {
            $ft = $context->context->functionType($void, false, $i8p, $i8p, $i8pp, $sizeT);
            $fn = $context->module->addFunction('phpc_attr_register_method_attrs', $ft);
            $context->registerFunction('phpc_attr_register_method_attrs', $fn);
        } else {
            $context->registerFunction(
                'phpc_attr_register_method_attrs',
                $context->module->getNamedFunction('phpc_attr_register_method_attrs')
            );
        }
        if (null === $context->module->getNamedFunction('phpc_attr_register_class_arg_flat')) {
            $ft = $context->context->functionType(
                $void,
                false,
                $i8p,
                $sizeT,
                $sizeT,
                $i8p,
                $i32,
                $i64,
                $double,
                $i8p,
                $i32
            );
            $fn = $context->module->addFunction('phpc_attr_register_class_arg_flat', $ft);
            $context->registerFunction('phpc_attr_register_class_arg_flat', $fn);
        } else {
            $context->registerFunction(
                'phpc_attr_register_class_arg_flat',
                $context->module->getNamedFunction('phpc_attr_register_class_arg_flat')
            );
        }
    }

    /**
     * @param list<string>|list<AttributeEntry> $namesOrEntries
     */
    public static function emitRegisterClass(Context $context, string $classLc, array $namesOrEntries): void
    {
        ReflectionRuntime::ensureLinked($context);
        self::registerDeclarations($context);
        if ([] === $namesOrEntries) {
            return;
        }

        $names = [];
        $entries = [];
        foreach ($namesOrEntries as $item) {
            if ($item instanceof AttributeEntry) {
                $entries[] = $item;
                $names[] = ltrim($item->name, '\\');
            } else {
                $names[] = ltrim((string) $item, '\\');
            }
        }

        self::$pendingClassLc = $classLc;
        self::$pendingMethodLc = null;
        self::$pendingNames = $names;
        self::$pendingEntries = $entries;
        $context->emitInInit([self::class, 'emitRegisterClassInInit']);
        self::$pendingClassLc = null;
        self::$pendingNames = [];
        self::$pendingEntries = [];
    }

    /** @param list<string> $names */
    public static function emitRegisterMethod(Context $context, string $classLc, string $methodLc, array $names): void
    {
        ReflectionRuntime::ensureLinked($context);
        self::registerDeclarations($context);
        if ([] === $names) {
            return;
        }

        self::$pendingClassLc = $classLc;
        self::$pendingMethodLc = $methodLc;
        self::$pendingNames = $names;
        self::$pendingEntries = [];
        $context->emitInInit([self::class, 'emitRegisterMethodInInit']);
        self::$pendingClassLc = null;
        self::$pendingMethodLc = null;
        self::$pendingNames = [];
        self::$pendingEntries = [];
    }

    public static function emitRegisterClassInInit(Context $ctx): void
    {
        if (null === self::$pendingClassLc || [] === self::$pendingNames) {
            return;
        }
        $classLc = self::$pendingClassLc;
        $names = self::$pendingNames;
        $entries = self::$pendingEntries;
        $i8p = $ctx->getTypeFromString('int8*');
        $i8pp = $ctx->getTypeFromString('int8**');
        $sizeT = $ctx->getTypeFromString('size_t');
        $i32 = $ctx->getTypeFromString('int32');
        $i64 = $ctx->getTypeFromString('int64');
        $ptrs = [];
        foreach ($names as $name) {
            $ptrs[] = $ctx->builder->pointerCast($ctx->constantFromString($name), $i8p);
        }
        $count = count($ptrs);
        $buf = $ctx->builder->call(
            $ctx->lookupFunction('__mm__malloc'),
            $sizeT->constInt($count * 8, false)
        );
        $arrPtr = $ctx->builder->pointerCast($buf, $i8pp);
        foreach ($ptrs as $idx => $ptr) {
            $slot = $ctx->builder->inBoundsGEP($arrPtr, $sizeT->constInt($idx, false));
            $ctx->builder->store($ptr, $slot);
        }
        $ctx->builder->call(
            $ctx->lookupFunction('phpc_attr_register_class_attrs'),
            $ctx->builder->pointerCast($ctx->constantFromString($classLc), $i8p),
            $arrPtr,
            $sizeT->constInt($count, false)
        );

        $flatFn = $ctx->lookupFunction('phpc_attr_register_class_arg_flat');
        foreach ($entries as $attrIdx => $entry) {
            if (!$entry instanceof AttributeEntry) {
                continue;
            }
            foreach ($entry->args as $argIdx => $spec) {
                $argName = null !== $spec['name'] ? $spec['name'] : '';
                $type = 0;
                $lval = $i64->constInt(0, false);
                $dval = $ctx->constantFromFloat(0.0, 'double');
                $sval = $ctx->builder->pointerCast($ctx->constantFromString(''), $i8p);
                $bval = $i32->constInt(0, false);
                $value = $spec['value'];
                if (null === $value) {
                    $type = 0;
                } elseif (is_bool($value)) {
                    $type = 1;
                    $bval = $i32->constInt($value ? 1 : 0, false);
                } elseif (is_int($value)) {
                    $type = 2;
                    $lval = $i64->constInt($value, false);
                } elseif (is_float($value)) {
                    $type = 3;
                    $dval = $ctx->constantFromFloat($value, 'double');
                } elseif (is_string($value)) {
                    $type = 4;
                    $sval = $ctx->builder->pointerCast($ctx->constantFromString($value), $i8p);
                } else {
                    continue;
                }
                $ctx->builder->call(
                    $flatFn,
                    $ctx->builder->pointerCast($ctx->constantFromString($classLc), $i8p),
                    $sizeT->constInt($attrIdx, false),
                    $sizeT->constInt($argIdx, false),
                    $ctx->builder->pointerCast($ctx->constantFromString($argName), $i8p),
                    $i32->constInt($type, false),
                    $lval,
                    $dval,
                    $sval,
                    $bval
                );
            }
        }
    }

    public static function emitRegisterMethodInInit(Context $ctx): void
    {
        if (null === self::$pendingClassLc || null === self::$pendingMethodLc || [] === self::$pendingNames) {
            return;
        }
        $classLc = self::$pendingClassLc;
        $methodLc = self::$pendingMethodLc;
        $names = self::$pendingNames;
        $i8p = $ctx->getTypeFromString('int8*');
        $i8pp = $ctx->getTypeFromString('int8**');
        $sizeT = $ctx->getTypeFromString('size_t');
        $ptrs = [];
        foreach ($names as $name) {
            $ptrs[] = $ctx->builder->pointerCast($ctx->constantFromString($name), $i8p);
        }
        $count = count($ptrs);
        $buf = $ctx->builder->call(
            $ctx->lookupFunction('__mm__malloc'),
            $sizeT->constInt($count * 8, false)
        );
        $arrPtr = $ctx->builder->pointerCast($buf, $i8pp);
        foreach ($ptrs as $idx => $ptr) {
            $slot = $ctx->builder->inBoundsGEP($arrPtr, $sizeT->constInt($idx, false));
            $ctx->builder->store($ptr, $slot);
        }
        $ctx->builder->call(
            $ctx->lookupFunction('phpc_attr_register_method_attrs'),
            $ctx->builder->pointerCast($ctx->constantFromString($classLc), $i8p),
            $ctx->builder->pointerCast($ctx->constantFromString($methodLc), $i8p),
            $arrPtr,
            $sizeT->constInt($count, false)
        );
    }
}
