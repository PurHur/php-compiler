<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPLLVM\Value;

/**
 * set_include_path() — replace include_path (ext/standard/basic_functions.c; #3223).
 *
 * Z_PARAM_PATH $include_path — null TypeError on PHP_COMPILER_PROFILE=8.4 (#20254).
 */
final class set_include_path extends Internal
{
    public function __construct()
    {
        parent::__construct('set_include_path');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'set_include_path() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        // Z_PARAM_PATH — null TypeError on PROFILE=8.4 (#20254; shared path guard).
        $newPath = VmString::coercePathBuiltinArg(
            $frame->calledArgs[0],
            'set_include_path',
            0,
            'include_path'
        );
        $old = VmIncludePath::push($newPath);
        BuiltinExecute::writeReturn($frame, static function ($ret) use ($old): void {
            if (false === $old) {
                $ret->bool(false);

                return;
            }
            $ret->string($old);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(
                'set_include_path() expects exactly 1 argument, '.\count($args).' given'
            );
        }
        $lit = JitStringBuiltinArg::compileTimeLiteral($args[0]);
        if (null !== $lit) {
            VmString::rejectNullByteBuiltinStringArg($lit, 'set_include_path', 0, 'include_path');
        }
        $nullPath = JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false);
        $newPath = JitStringBuiltinArg::lowerPath(
            $context,
            $args[0],
            'set_include_path',
            0,
            'include_path'
        );
        if (
            $nullPath
            && (
                $context->callerStrictTypes
                || JitStringBuiltinArg::requiresZparamStrStrictNullOnForwardProfile()
            )
        ) {
            // lowerPath already emitted TypeError+abort; skip setValidated after terminator (#20254).
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

            return $ptr;
        }

        return JitIncludePath::setValidated($context, $newPath);
    }
}
