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
 * Z_PARAM_PATH $include_path — soft-null DEP+coerce; caller strict_types → TypeError (#30359, #20254).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/basic_functions.c PHP_FUNCTION(set_include_path)
 * @see https://github.com/php/php-src/blob/master/ext/standard/basic_functions.stub.php
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
        // Z_PARAM_PATH — caller strict_types → TypeError on null; else soft-null (#30359).
        $newPath = VmFilestatArg::filenameArgForFrame(
            $frame,
            0,
            'set_include_path',
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
        // Soft-null outside strict_types; strict → TypeError (#30359).
        // Early return after compile-time null TypeError — no setValidated after abort
        // (AOT module verify: terminator mid-block; peer getopt #30358).
        if ($nullPath && $context->callerStrictTypes) {
            JitStringBuiltinArg::lowerPath(
                $context,
                $args[0],
                'set_include_path',
                0,
                'include_path'
            );

            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }
        $newPath = JitStringBuiltinArg::lowerPath(
            $context,
            $args[0],
            'set_include_path',
            0,
            'include_path'
        );

        return JitIncludePath::setValidated($context, $newPath);
    }
}
