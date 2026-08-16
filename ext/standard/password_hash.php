<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** password_hash() — PASSWORD_DEFAULT / PASSWORD_BCRYPT / PASSWORD_ARGON2* (VM); JIT/AOT bcrypt via libcrypt (#172, #4149). */
final class password_hash extends Internal
{
    public function __construct()
    {
        parent::__construct('password_hash');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/password.c — ArgumentCountError (#25407).
        $this->requireArgCountRange($frame, 'password_hash', 2, 3);
        $argc = \count($frame->calledArgs);
        // Z_PARAM_STR — soft-null DEP+coerce on PROFILE=8.4 (#21210, reverts #20174; password.c).
        $password = VmString::trimFamilyStringArgForFrame($frame, 0, 'password_hash', 0, 'password');
        $algo = VmPassword::resolveAlgo($frame->calledArgs[1], 'password_hash', 1, 'algo');
        $options = [];
        if (3 === $argc) {
            // php-src Z_PARAM_ARRAY $options — TypeError on null (password.stub.php; #31421).
            VmArray::requireArrayParam($frame->calledArgs[2], 'password_hash', 3, 'options');
            $exported = VmJson::export($frame->calledArgs[2]->resolveIndirect());
            $options = \is_array($exported) ? $exported : [];
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmPassword::hash($password, $algo, $options);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireArgCountRangeJit($context, $args, 'password_hash', 2, 3)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }
        $argc = \count($args);
        $options = null;
        if (3 === $argc) {
            // php-src Z_PARAM_ARRAY $options — TypeError on null (#31421).
            JitArrayElem::requireArrayParam($context, $args[2], 'password_hash', 3, 'options');
            $options = $args[2];
        }

        return JitPassword::hash(
            $context,
            // Z_PARAM_STR — soft-null DEP+coerce on PROFILE=8.4 (#21210, reverts #20174; password.c).
            JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'password_hash', 0, 'password'),
            JitPasswordAlgo::lower($context, $args[1], 'password_hash', 1, 'algo'),
            $options
        );
    }
}
