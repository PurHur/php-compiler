<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * checkdnsrr() and dns_check_record() alias — DNS record existence probe (#5983).
 *
 * VM: VmDns (libc res_query FFI + host fallback). VM only — JIT/AOT phase 2 (#5983).
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
        if (null === $frame->returnVar) {
            return;
        }
        $hostname = VmString::coerceStringBuiltinArg($frame->calledArgs[0], $fn, 0, 'hostname');
        $type = 'MX';
        if ($argc >= 2) {
            $typeVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_ARRAY === $typeVar->type) {
                throw new \TypeError(VmString::stringBuiltinTypeError($fn, 1, 'type', 'array'));
            }
            $type = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $fn, 1, 'type');
        }
        $frame->returnVar->bool(VmDns::checkdnsrr($hostname, $type));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $fn = $this->getName();
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException($fn.'() requires one or two arguments in this compiler build');
        }
        JitStringBuiltinArg::lower($context, $args[0], $fn, 0, 'hostname');
        if ($argc >= 2) {
            JitStringBuiltinArg::lower($context, $args[1], $fn, 1, 'type');
        }
        throw new \LogicException($fn.'() is VM-only in this compiler build (issue #5983 phase 1)');
    }
}
