<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** password_needs_rehash() — VM via VmPassword; JIT/AOT via PasswordJitHelper PHP (issue #3279, #6503, #9908, #18655). */
final class password_needs_rehash extends Internal
{
    public function __construct()
    {
        parent::__construct('password_needs_rehash');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'password_needs_rehash() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'password_needs_rehash() expects at most 3 arguments, %d given',
                $argc
            ));
        }
        // Z_PARAM_STR — soft-null DEP+coerce on PROFILE=8.4 (#21314, ext/standard/password.c).
        $hash = VmString::trimFamilyStringArgForFrame(
            $frame,
            0,
            'password_needs_rehash',
            0,
            'hash'
        );
        $algo = VmPassword::resolveAlgo($frame->calledArgs[1], 'password_needs_rehash', 1, 'algo');
        $options = [];
        if (3 === $argc) {
            // php-src Z_PARAM_ARRAY $options — TypeError on null (password.stub.php; #31421).
            VmArray::requireArrayParam($frame->calledArgs[2], 'password_needs_rehash', 3, 'options');
            $exported = VmJson::export($frame->calledArgs[2]->resolveIndirect());
            $options = \is_array($exported) ? $exported : [];
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(
            VmPassword::needsRehash($hash, $algo, $options)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'password_needs_rehash() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'password_needs_rehash() expects at most 3 arguments, %d given',
                $argc
            ));
        }
        $options = null;
        if (3 === $argc) {
            // php-src Z_PARAM_ARRAY $options — TypeError on null (#31421).
            JitArrayElem::requireArrayParam($context, $args[2], 'password_needs_rehash', 3, 'options');
            $options = $args[2];
        }

        return JitPasswordNeedsRehash::invoke(
            $context,
            // Z_PARAM_STR — soft-null DEP+coerce on PROFILE=8.4 (#21314, ext/standard/password.c).
            JitStringBuiltinArg::lowerTrimFamilyString(
                $context,
                $args[0],
                'password_needs_rehash',
                0,
                'hash'
            ),
            $args[1],
            $options
        );
    }
}
