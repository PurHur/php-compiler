<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * collator_create() — procedural alias of Collator::create (php-src collator_create.c; #5747).
 *
 * Reflection return ?Collator via {@see \PHPCompiler\BuiltinInternalArgInfo} (#25497).
 * Z_PARAM_STR $locale — null TypeError (#29933).
 */
final class collator_create extends Internal
{
    public function __construct()
    {
        parent::__construct('collator_create');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'collator_create() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $locale = VmCollator::coerceLocaleArg($frame->calledArgs[0], 'collator_create', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object(VmCollator::create($frame->vmContext, $locale));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'collator_create() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        // Compile-time / typed null — Z_PARAM_STR (#29933, collator.stub.php).
        $nullConst = JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false);
        if ($nullConst) {
            JitNativeString::ensureInsertBlock($context);
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'collator_create(): Argument #1 ($locale) must be of type string, null given'
            );

            return self::boxNullResult($context);
        }

        throw new \Error('collator_create() is not implemented for JIT in this compiler build (issue #5747)');
    }

    private static function boxNullResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $ptr
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }
}
