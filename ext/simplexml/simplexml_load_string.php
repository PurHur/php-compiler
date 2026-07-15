<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** simplexml_load_string() — parse XML into SimpleXMLElement tree (#3338). */
final class simplexml_load_string extends Internal
{
    public function __construct()
    {
        parent::__construct('simplexml_load_string');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError('simplexml_load_string() expects at least 1 argument, 0 given');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('simplexml_load_string() requires VM context');
        }
        $data = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'simplexml_load_string',
            0,
            'data',
            'string',
            false
        );
        $entry = VmSimpleXml::loadString($frame->vmContext, $data, $frame);
        if (null !== $frame->returnVar) {
            if (null === $entry) {
                $frame->returnVar->bool(false);
            } else {
                $frame->returnVar->object($entry);
            }
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('simplexml_load_string() is not JIT-lowered in this compiler build');
    }
}
