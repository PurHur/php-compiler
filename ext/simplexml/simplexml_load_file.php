<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** simplexml_load_file() — load XML file into SimpleXMLElement tree (#3338). */
final class simplexml_load_file extends Internal
{
    public function __construct()
    {
        parent::__construct('simplexml_load_file');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError('simplexml_load_file() expects at least 1 argument, 0 given');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('simplexml_load_file() requires VM context');
        }
        // Z_PARAM_PATH $filename — soft-null DEP+coerce on PROFILE=8.4 (#21502, reverts #20352 TypeError;
        // php-src ext/simplexml/simplexml.c — Zend deprecates null → '', then I/O warning + false).
        $filename = VmString::coercePathBuiltinArg(
            $frame->calledArgs[0],
            'simplexml_load_file',
            0,
            'filename'
        );
        $entry = VmSimpleXml::loadFile($frame->vmContext, $filename, $frame);
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
        throw new \LogicException('simplexml_load_file() is not JIT-lowered in this compiler build');
    }
}
