<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** Emit user class method tables into __init__ for JIT/AOT get_class_methods() (#3118). */
final class MethodRegistry
{
    private static ?string $pendingClassLc = null;
    private static ?string $pendingMethodLc = null;
    private static ?string $pendingDisplayName = null;
    private static int $pendingVisibility = 0;
    private static ?string $pendingParentClassLc = null;
    private static ?string $pendingChildClassLc = null;

    public static function registerDeclarations(Context $context): void
    {
        $void = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');

        if (null === $context->module->getNamedFunction('phpc_class_methods_register')) {
            $ft = $context->context->functionType($void, false, $i8p, $i8p, $i8p, $i32);
            $fn = $context->module->addFunction('phpc_class_methods_register', $ft);
            $context->registerFunction('phpc_class_methods_register', $fn);
        }
        if (null === $context->module->getNamedFunction('phpc_class_methods_set_parent')) {
            $ft = $context->context->functionType($void, false, $i8p, $i8p);
            $fn = $context->module->addFunction('phpc_class_methods_set_parent', $ft);
            $context->registerFunction('phpc_class_methods_set_parent', $fn);
        }
        if (null === $context->module->getNamedFunction('phpc_get_class_methods')) {
            $void = $context->getTypeFromString('void');
            $valuePtr = $context->getTypeFromString('__value__*');
            $ft = $context->context->functionType($void, false, $i8p, $i32, $valuePtr);
            $fn = $context->module->addFunction('phpc_get_class_methods', $ft);
            $context->registerFunction('phpc_get_class_methods', $fn);
        }
    }

    public static function emitRegisterMethod(
        Context $context,
        string $classLc,
        string $methodLc,
        string $displayName,
        int $visibility
    ): void {
        self::registerDeclarations($context);
        self::$pendingClassLc = $classLc;
        self::$pendingMethodLc = $methodLc;
        self::$pendingDisplayName = $displayName;
        self::$pendingVisibility = $visibility;
        $context->emitInInit([self::class, 'emitRegisterMethodInInit']);
        self::$pendingClassLc = null;
        self::$pendingMethodLc = null;
        self::$pendingDisplayName = null;
        self::$pendingVisibility = 0;
    }

    public static function emitSetParent(Context $context, string $childClassLc, string $parentClassLc): void
    {
        self::registerDeclarations($context);
        self::$pendingChildClassLc = $childClassLc;
        self::$pendingParentClassLc = $parentClassLc;
        $context->emitInInit([self::class, 'emitSetParentInInit']);
        self::$pendingChildClassLc = null;
        self::$pendingParentClassLc = null;
    }

    public static function emitRegisterMethodInInit(Context $ctx): void
    {
        if (null === self::$pendingClassLc || null === self::$pendingMethodLc || null === self::$pendingDisplayName) {
            return;
        }
        $i8p = $ctx->getTypeFromString('int8*');
        $i32 = $ctx->getTypeFromString('int32');
        $ctx->builder->call(
            $ctx->lookupFunction('phpc_class_methods_register'),
            $ctx->builder->pointerCast($ctx->constantFromString(self::$pendingClassLc), $i8p),
            $ctx->builder->pointerCast($ctx->constantFromString(self::$pendingMethodLc), $i8p),
            $ctx->builder->pointerCast($ctx->constantFromString(self::$pendingDisplayName), $i8p),
            $i32->constInt(self::$pendingVisibility, false)
        );
    }

    public static function emitSetParentInInit(Context $ctx): void
    {
        if (null === self::$pendingChildClassLc || null === self::$pendingParentClassLc) {
            return;
        }
        $i8p = $ctx->getTypeFromString('int8*');
        $ctx->builder->call(
            $ctx->lookupFunction('phpc_class_methods_set_parent'),
            $ctx->builder->pointerCast($ctx->constantFromString(self::$pendingChildClassLc), $i8p),
            $ctx->builder->pointerCast($ctx->constantFromString(self::$pendingParentClassLc), $i8p)
        );
    }
}
