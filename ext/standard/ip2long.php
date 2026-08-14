<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * ip2long() — IPv4 string to 32-bit integer (ext/standard/basic_functions.c, #3225).
 *
 * Excess/missing argc → Zend ArgumentCountError (#30546).
 */
final class ip2long extends Internal
{
    public function __construct()
    {
        parent::__construct('ip2long');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 1 (#30546; ext/standard/basic_functions.stub.php).
        $this->requireExactArgCount($frame, 'ip2long', 1);
        // Z_PARAM_STR — caller strict_types → TypeError on null; else soft-null (#29785).
        $ip = VmString::stringBuiltinArgForFrame($frame, 0, 'ip2long', 0, 'ip', false);
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmInet::ip2long($ip);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30546).
        if (!$this->requireExactJitArgCount($context, $args, 'ip2long', 1)) {
            return $context->getTypeFromString('__value__*')->constNull();
        }
        // Soft-null outside strict_types; strict → TypeError (#29785).
        $ip = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'ip2long', 0, 'ip')
            : JitStringBuiltinArg::lower($context, $args[0], 'ip2long', 0, 'ip', 'string', null, false);

        return JitInet::ip2long($context, $ip);
    }
}
