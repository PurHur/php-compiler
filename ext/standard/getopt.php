<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * getopt() — CLI option parsing (ext/standard/php_getopt.c parity, issue #3251).
 *
 * VM: GetoptEngine over SAPI argv snapshot. JIT/AOT: VM-only v1.
 */
final class getopt extends Internal
{
    public function __construct()
    {
        parent::__construct('getopt');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'getopt() expects between 1 and 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('getopt() requires VM context in this compiler build');
        }

        $shortOptions = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'getopt',
            0,
            'short_options'
        );

        $longOptions = [];
        if ($argc >= 2) {
            $longArg = $frame->calledArgs[1]->resolveIndirect();
            if (EnumCaseSupport::isEnumCaseVariable($longArg)) {
                throw new \TypeError(\sprintf(
                    'getopt(): Argument #2 ($long_options) must be of type array, %s given',
                    EnumCaseSupport::typeNameForVariable($longArg)
                ));
            }
            if (Variable::TYPE_ARRAY !== $longArg->type) {
                throw new \TypeError(\sprintf(
                    'getopt(): Argument #2 ($long_options) must be of type array, %s given',
                    EnumCaseSupport::typeNameForVariable($longArg)
                ));
            }
            foreach ($longArg->toArray()->iterate(true) as $entry) {
                $entry = $entry->resolveIndirect();
                $longOptions[] = VmString::coerceStringBuiltinArg($entry, 'getopt', 1, 'long_options');
            }
        }

        $restIndex = null;
        $restIndexArg = null;
        if ($argc >= 3) {
            $restIndexArg = $frame->calledArgs[2];
            VmGetopt::validateRestIndexByRef($restIndexArg, 'getopt', 2);
        }

        $parsed = GetoptEngine::parse(
            $frame->vmContext->cliRequestArgv,
            $shortOptions,
            $longOptions,
            $restIndex
        );
        if (null !== $restIndexArg && null !== $restIndex) {
            VmGetopt::writeRestIndex($restIndexArg, $restIndex);
        }
        if (false === $parsed) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom(VmJson::import($parsed));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('getopt() is not implemented for JIT in this compiler build (issue #3251)');
    }
}
