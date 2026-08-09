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
 * checkdnsrr() and dns_check_record() alias — DNS record existence probe (#5983).
 *
 * VM: VmDns (libc res_query FFI + host fallback). JIT/AOT: CheckdnsrrRuntime → CheckdnsrrJitHelper PHP (#9379).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/dns.c PHP_FUNCTION(checkdnsrr)
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
        $hostname = VmString::coerceStringBuiltinArg($frame->calledArgs[0], $fn, 0, 'hostname', 'string', false);
        VmString::rejectEmptyBuiltinStringArg($hostname, $fn, 0, 'hostname');
        $type = 'MX';
        if ($argc >= 2) {
            $typeVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_ARRAY === $typeVar->type) {
                throw new \TypeError(VmString::stringBuiltinTypeError($fn, 1, 'type', 'array'));
            }
            $type = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $fn, 1, 'type');
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

        $hostname = JitStringBuiltinArg::lower($context, $args[0], $fn, 0, 'hostname', 'string', null, false);
        JitStringBuiltinArg::rejectEmpty(
            $context,
            $args[0],
            $hostname,
            $fn.'(): Argument #1 ($hostname) must not be empty'
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
            $type = JitStringBuiltinArg::lower($context, $args[1], $fn, 1, 'type');
        } else {
            $type = JitCheckdnsrr::literalType($context, 'MX');
        }

        return JitCheckdnsrr::invoke($context, $hostname, $type);
    }
}
