<?php

declare(strict_types=1);

namespace PHPCompiler\ext\soap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * is_soap_fault() — true when value is a SoapFault instance (php-src ext/soap/soap.c; #20124).
 */
final class is_soap_fault extends Internal
{
    public function __construct()
    {
        parent::__construct('is_soap_fault');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'is_soap_fault', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $arg = $frame->calledArgs[0]->resolveIndirect();
        $frame->returnVar->bool(self::isSoapFaultVariable($arg));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitIsSoapFault::invoke($context, ...$args);
    }

    public static function isSoapFaultVariable(Variable $var): bool
    {
        if (Variable::TYPE_OBJECT !== $var->type) {
            return false;
        }
        $obj = $var->toObject();
        $lc = \strtolower($obj->class->name);
        if ('soapfault' === $lc) {
            return true;
        }
        $parent = $obj->class->parentLc;
        while (null !== $parent && '' !== $parent) {
            if ('soapfault' === $parent) {
                return true;
            }
            // Walk via context is not available here; SoapFault is final in practice.
            break;
        }

        return false;
    }
}
