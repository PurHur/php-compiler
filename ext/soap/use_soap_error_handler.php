<?php

declare(strict_types=1);

namespace PHPCompiler\ext\soap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmMath;
use PHPLLVM\Value;

/**
 * use_soap_error_handler() — toggle SOAP error→SoapFault handler (php-src ext/soap/soap.c; #20267).
 *
 * Returns the previous flag value; default $enable is true (php-src `|b` with handler=1).
 *
 * @see https://github.com/php/php-src/blob/master/ext/soap/soap.c PHP_FUNCTION(use_soap_error_handler)
 */
final class use_soap_error_handler extends Internal
{
    public function __construct()
    {
        parent::__construct('use_soap_error_handler');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'use_soap_error_handler() expects at most 1 argument, '.$argc.' given'
            );
        }
        $enable = true;
        if ($argc >= 1) {
            $enable = VmMath::parseBoolBuiltinArg(
                $frame->calledArgs[0],
                'use_soap_error_handler',
                1,
                'enable'
            );
        }
        $previous = SoapExtensionPolicy::useSoapErrorHandler();
        SoapExtensionPolicy::setUseSoapErrorHandler($enable);
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->bool($previous)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitUseSoapErrorHandler::invoke($context, ...$args);
    }
}
