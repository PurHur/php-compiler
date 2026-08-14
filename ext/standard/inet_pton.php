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
 * inet_pton() — printable address to binary form (ext/standard/basic_functions.c, #3225).
 *
 * php-src stub names the parameter `$ip` (not historical `$ip_address`) — #28916.
 * Excess/missing argc → Zend ArgumentCountError (#30546).
 */
final class inet_pton extends Internal
{
    public function __construct()
    {
        parent::__construct('inet_pton');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 1 (#30546; ext/standard/basic_functions.stub.php).
        $this->requireExactArgCount($frame, 'inet_pton', 1);
        // Z_PARAM_STR — caller strict_types → TypeError on null; else soft-null (#29785 / #20303).
        $address = VmString::stringBuiltinArgForFrame($frame, 0, 'inet_pton', 0, 'ip', false);
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmInet::inet_pton($address);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30546).
        if (!$this->requireExactJitArgCount($context, $args, 'inet_pton', 1)) {
            return $context->getTypeFromString('__value__*')->constNull();
        }
        // Soft-null outside strict_types; strict → TypeError (#29785).
        $address = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'inet_pton', 0, 'ip')
            : JitStringBuiltinArg::lower($context, $args[0], 'inet_pton', 0, 'ip', 'string', null, false);

        return JitInet::inetPton($context, $address);
    }
}
