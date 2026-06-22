<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;

/**
 * MCJIT embed ob_* bodies — thin bridges to EmbedObJitHelper PHP SSOT + write(2) (#98, #2055, #9956).
 */
final class EmbedObOutput
{
    /** @var list<string> */
    private const NOOP_FUNCTIONS = [
        '__phpc_ob_start', '__phpc_flush', '__phpc_ob_end_all', '__phpc_ob_implicit_flush',
    ];

    /** @var list<string> */
    private const ZERO_FUNCTIONS = [
        '__phpc_ob_get_level', '__phpc_ob_buffer_used_at', '__phpc_ob_get_clean',
        '__phpc_ob_get_contents', '__phpc_ob_get_length', '__phpc_ob_end_clean',
        '__phpc_ob_get_flush', '__phpc_ob_end_flush', '__phpc_ob_flush', '__phpc_ob_clean',
    ];

    public static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_EMBED !== $context->loadType) {
            return;
        }
        $probe = $context->module->getNamedFunction('__phpc_ob_echo_ll');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinked($context);

            return;
        }

        LibcExtern::register($context);
        foreach (self::NOOP_FUNCTIONS as $name) {
            self::implementNoop($context, $name);
        }
        foreach (self::ZERO_FUNCTIONS as $name) {
            self::implementReturnZero($context, $name);
        }
        EmbedObEchoBridge::implementAll($context);
        self::registerLinked($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementNoop(Context $context, string $name): void
    {
        $fn = $context->module->getNamedFunction($name);
        if (null === $fn || $fn->countBasicBlocks() > 0) {
            return;
        }
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementReturnZero(Context $context, string $name): void
    {
        $fn = $context->module->getNamedFunction($name);
        if (null === $fn || $fn->countBasicBlocks() > 0) {
            return;
        }
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $fnType = $fn->typeOf();
        if ($fnType instanceof \PHPLLVM\Type\Pointer) {
            $fnType = $fnType->getElementType();
        }
        $retTy = $fnType instanceof \PHPLLVM\Type\Function_
            ? $context->getStringFromType($fnType->getReturnType())
            : 'int32';
        $ty = $context->getTypeFromString('int64' === $retTy ? 'int64' : 'int32');
        $context->builder->returnValue($ty->constInt(0, false));
        $context->builder->clearInsertionPosition();
    }

    private static function registerLinked(Context $context): void
    {
        foreach (\array_merge(self::NOOP_FUNCTIONS, self::ZERO_FUNCTIONS, [
            '__phpc_ob_echo_cstr', '__phpc_ob_echo_char', '__phpc_ob_echo_ll',
            '__phpc_ob_echo_double', '__phpc_ob_echo_substr',
        ]) as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after EmbedObOutput bridge (#9956)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
