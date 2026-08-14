<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * getprotobyname() — protocol number by name (JIT/AOT via libc, issue #4024, #30282).
 *
 * Excess/missing argc → Zend ArgumentCountError (#30546).
 * Z_PARAM_STR: strict_types → TypeError on null; soft path DEP+coerce (#30282).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/network.c PHP_FUNCTION(getprotobyname)
 * @see https://github.com/php/php-src/blob/master/ext/standard/basic_functions.stub.php string $protocol
 */
final class getprotobyname extends Internal
{
    public function __construct()
    {
        parent::__construct('getprotobyname');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 1 (#30546; ext/standard/basic_functions.stub.php).
        $this->requireExactArgCount($frame, 'getprotobyname', 1);
        if (null === $frame->returnVar) {
            return;
        }
        // Z_PARAM_STR — caller strict_types → TypeError on null; else soft-null (#30282).
        $name = VmString::stringBuiltinArgForFrame($frame, 0, 'getprotobyname', 0, 'protocol', false);
        $number = VmNetworkServices::getprotobyname($name);
        if (false === $number) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($number);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30546).
        if (!$this->requireExactJitArgCount($context, $args, 'getprotobyname', 1)) {
            return $context->getTypeFromString('__value__*')->constNull();
        }

        // Soft-null outside strict_types; strict → TypeError (#30282).
        // Early return after compile-time null TypeError — no libc call after abort
        // (AOT module verify: terminator mid-block; peer getservbyname #30281).
        if ($context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false))) {
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'getprotobyname', 0, 'protocol', 'string', null, false);

            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }

        return JitNetworkServices::getprotobyname($context, $args[0]);
    }
}
