<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitIterableArg;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * iterator_to_array() — copy Traversable (array or Generator) into an array (#3100).
 *
 * VM: {@see VM::iteratorToArray()}; JIT: {@see JitIteratorToArray}.
 * Default preserve_keys=true matches Zend/php-src (ext/spl/iterator.c).
 *
 * Null iterator always TypeError (typed Traversable|array; not string soft-null) — #21893.
 * Null $preserve_keys: Z_PARAM_BOOL — strict TypeError; else DEP+coerce (#31340).
 *
 * php-src: ext/spl/iterator.c — PHP_FUNCTION(iterator_to_array)
 * php-src: ext/spl/spl.stub.php — Traversable|array $iterator, bool $preserve_keys = true
 */
final class iterator_to_array extends Internal
{
    public function __construct()
    {
        parent::__construct('iterator_to_array');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/spl/iterator.c — ArgumentCountError (#30575).
        $this->requireArgCountRange($frame, 'iterator_to_array', 1, 2);
        $argc = \count($frame->calledArgs);
        if (null === $frame->vmContext) {
            throw new \LogicException('iterator_to_array() requires VM context in this compiler build');
        }
        $iterator = $frame->calledArgs[0]->resolveIndirect();
        $preserveKeys = true;
        if (2 === $argc) {
            // Z_PARAM_BOOL: caller strict_types → TypeError on null; else soft-null DEP+coerce (#31340).
            $preserveKeys = VmMath::parseBoolBuiltinArgForFrame(
                $frame,
                1,
                'iterator_to_array',
                2,
                'preserve_keys'
            );
        }
        $out = $frame->vmContext->runtime->vm->iteratorToArray($iterator, $preserveKeys, $frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->array($out);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT/JIT) — #30575.
        if (!$this->requireArgCountRangeJit($context, $args, 'iterator_to_array', 1, 2)) {
            return $context->getTypeFromString('__value__*')->constNull();
        }
        $argc = \count($args);
        ExceptionBridge::ensureLinked($context);
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            // Always TypeError — typed Traversable|array (php-src-strict; #21893).
            // Do not soft-null / empty-array; InternalStrictArg must not gate this.
            JitIterableArg::emitIterableTypeErrorAndAbort(
                $context,
                'iterator_to_array',
                0,
                'iterator',
                'null'
            );

            return $context->getTypeFromString('__value__*')->constNull();
        }
        // Constant-boxed null (`$x = null`) — reject before Iterator/Generator protocol (#27634).
        if (!JitIterableArg::guardIterableOperand($context, $args[0], 'iterator_to_array')) {
            return $context->getTypeFromString('__value__*')->constNull();
        }
        if (2 === $argc) {
            // Compile-time null under strict: catchable TypeError then stop IR (#31340 / peer #31358).
            if ($context->callerStrictTypes && (
                JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)
            )) {
                JitNativeString::ensureInsertBlock($context);
                ExceptionBridge::emitTypeErrorAndAbort(
                    $context,
                    'iterator_to_array(): Argument #2 ($preserve_keys) must be of type bool, null given'
                );
                JitNativeString::ensureInsertBlock($context);

                return $context->getTypeFromString('__value__*')->constNull();
            }
            $preserveConst = self::compileTimePreserveKeys($context, $args[1]);
            if (null !== $preserveConst) {
                // Avoid diamond CFG when preserve_keys is a literal (#26802).
                return JitIteratorToArray::invoke($context, $args[0], $preserveConst);
            }
            // Z_PARAM_BOOL: strict TypeError on null; else null→false + E_DEPRECATED (#31340).
            $preserveKeys = JitBoolArg::lowerCoerceZParamBool(
                $context,
                $args[1],
                'iterator_to_array',
                'preserve_keys',
                2
            );

            return JitIteratorToArray::invokeWithPreserveKeysFlag($context, $args[0], $preserveKeys);
        }

        return JitIteratorToArray::invoke(
            $context,
            $args[0],
            true,
        );
    }

    private static function compileTimePreserveKeys(Context $context, JITVariable $var): ?bool
    {
        if (null !== $var->compileTimeConstantName) {
            $name = strtolower($var->compileTimeConstantName);
            if ('true' === $name) {
                return true;
            }
            if ('false' === $name) {
                return false;
            }
        }
        if (null !== $var->compileTimeLong) {
            return 0 !== $var->compileTimeLong;
        }
        if (null === $var->value) {
            return null;
        }
        $lib = $context->llvm->lib;
        if (JITVariable::TYPE_NATIVE_BOOL === $var->type
            && null !== $lib->LLVMIsAConstantInt($var->value->value)) {
            return 0 !== (int) $lib->LLVMConstIntGetZExtValue($var->value->value);
        }
        if (JITVariable::TYPE_NATIVE_LONG === $var->type
            && null !== $lib->LLVMIsAConstantInt($var->value->value)) {
            return 0 !== (int) $lib->LLVMConstIntGetZExtValue($var->value->value);
        }

        return null;
    }
}
