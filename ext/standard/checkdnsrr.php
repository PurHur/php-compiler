<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * checkdnsrr() and dns_check_record() alias — DNS record existence probe (#5983, #30261).
 *
 * VM: VmDns (libc res_query FFI + host fallback). JIT/AOT: CheckdnsrrRuntime → CheckdnsrrJitHelper PHP (#9379).
 * Z_PARAM_STR: strict_types → TypeError on null; soft path DEP+coerce then empty ValueError (#30261).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/dns.c PHP_FUNCTION(checkdnsrr)
 * @see https://github.com/php/php-src/blob/master/ext/standard/basic_functions.stub.php string $hostname
 */
final class checkdnsrr extends Internal
{
    public function __construct(string $name = 'checkdnsrr')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $fn = $this->getName();
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException($fn.'() requires one or two arguments in this compiler build');
        }
        // Z_PARAM_STR — caller strict_types → TypeError on null; else soft-null (#30261).
        $hostname = VmString::stringBuiltinArgForFrame($frame, 0, $fn, 0, 'hostname', false);
        VmString::rejectEmptyBuiltinStringArg($hostname, $fn, 0, 'hostname', true);
        $type = 'MX';
        if ($argc >= 2) {
            $typeVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_ARRAY === $typeVar->type) {
                throw new \TypeError(VmString::stringBuiltinTypeError($fn, 1, 'type', 'array'));
            }
            // Z_PARAM_STR $type — same null/strict rule as $hostname (#30261).
            $type = VmString::stringBuiltinArgForFrame($frame, 1, $fn, 1, 'type', false);
        }
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->bool(VmDns::checkdnsrr($hostname, $type))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $fn = $this->getName();
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException($fn.'() requires one or two arguments in this compiler build');
        }

        // Soft-null outside strict_types; strict → TypeError (#30261; peer gethostbyaddr #29809).
        // Early return after compile-time null TypeError — rejectEmpty must not emit after abort
        // (AOT module verify: terminator mid-block).
        if ($context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false))) {
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], $fn, 0, 'hostname');

            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }
        $hostname = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], $fn, 0, 'hostname')
            : JitStringBuiltinArg::lower($context, $args[0], $fn, 0, 'hostname', 'string', null, false);
        JitStringBuiltinArg::rejectEmpty(
            $context,
            $args[0],
            $hostname,
            VmString::emptyStringArgValueErrorMessageCannot($fn, 0, 'hostname')
        );
        if ($argc >= 2) {
            if (JITVariable::TYPE_HASHTABLE === $args[1]->type) {
                TypeErrorRaise::registerDeclarations($context);
                TypeErrorRaise::ensureLinked($context);
                TypeErrorRaise::emitRaise(
                    $context,
                    VmString::stringBuiltinTypeError($fn, 1, 'type', 'array')
                );
                $context->builder->call($context->lookupFunction('abort'));

                return JitValueBox::pointer($context, JitValueBox::alloc($context));
            }
            if ($context->callerStrictTypes
                && (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false))) {
                JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[1], $fn, 1, 'type');

                return JitValueBox::pointer($context, JitValueBox::alloc($context));
            }
            $type = $context->callerStrictTypes
                ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[1], $fn, 1, 'type')
                : JitStringBuiltinArg::lower($context, $args[1], $fn, 1, 'type', 'string', null, false);
        } else {
            $type = JitCheckdnsrr::literalType($context, 'MX');
        }

        return JitCheckdnsrr::invoke($context, $hostname, $type);
    }
}
