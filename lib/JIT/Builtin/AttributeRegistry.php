<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** Emit native attribute name tables into __init__ for JIT/AOT reflection (#1936). */
final class AttributeRegistry
{
    public static function registerDeclarations(Context $context): void
    {
        $void = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $context->getTypeFromString('int8**');
        $sizeT = $context->getTypeFromString('size_t');

        if (null === $context->module->getNamedFunction('phpc_attr_register_class_attrs')) {
            $ft = $context->context->functionType($void, false, $i8p, $i8pp, $sizeT);
            $fn = $context->module->addFunction('phpc_attr_register_class_attrs', $ft);
            $context->registerFunction('phpc_attr_register_class_attrs', $fn);
        }
        if (null === $context->module->getNamedFunction('phpc_attr_register_method_attrs')) {
            $ft = $context->context->functionType($void, false, $i8p, $i8p, $i8pp, $sizeT);
            $fn = $context->module->addFunction('phpc_attr_register_method_attrs', $ft);
            $context->registerFunction('phpc_attr_register_method_attrs', $fn);
        }
    }

    /** @param list<string> $names */
    public static function emitRegisterClass(Context $context, string $classLc, array $names): void
    {
        self::registerDeclarations($context);
        if ([] === $names) {
            return;
        }

        $context->emitInInit(static function (Context $ctx) use ($classLc, $names): void {
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
                $ctx->lookupFunction('phpc_attr_register_class_attrs'),
                $ctx->builder->pointerCast($ctx->constantFromString($classLc), $i8p),
                $arrPtr,
                $sizeT->constInt($count, false)
            );
        });
    }

    /** @param list<string> $names */
    public static function emitRegisterMethod(Context $context, string $classLc, string $methodLc, array $names): void
    {
        self::registerDeclarations($context);
        if ([] === $names) {
            return;
        }

        $context->emitInInit(static function (Context $ctx) use ($classLc, $methodLc, $names): void {
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
        });
    }
}
