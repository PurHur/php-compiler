<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * getopt() — CLI option parsing (ext/standard/php_getopt.c parity, issue #3251).
 *
 * VM: GetoptEngine over SAPI argv snapshot. JIT/AOT: GetoptJitHelper via JitGetopt (#3251 phase 2).
 * rest_index by-ref is VM-only.
 *
 * Z_PARAM_STR $short_options: caller strict_types → TypeError on null; soft path DEP+coerce (#30358).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/basic_functions.c PHP_FUNCTION(getopt)
 * @see https://github.com/php/php-src/blob/master/ext/standard/basic_functions.stub.php
 */
final class getopt extends Internal
{
    public function __construct()
    {
        parent::__construct('getopt');
    }

    public function execute(Frame $frame): void
    {
        // Named rest_index: alone leaves a hole at long_options (php-src basic_functions.stub.php; #25144).
        $maxIndex = -1;
        foreach (\array_keys($frame->calledArgs) as $index) {
            if (\is_int($index) && $index > $maxIndex) {
                $maxIndex = $index;
            }
        }
        $argc = $maxIndex + 1;
        if ($argc < 1 || $argc > 3 || !\array_key_exists(0, $frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'getopt() expects between 1 and 3 arguments, %d given',
                \count($frame->calledArgs)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('getopt() requires VM context in this compiler build');
        }

        // Z_PARAM_STR — caller strict_types → TypeError on null; else soft-null (#30358).
        $shortOptions = VmString::stringBuiltinArgForFrame(
            $frame,
            0,
            'getopt',
            0,
            'short_options',
            false
        );

        $longOptions = [];
        if (\array_key_exists(1, $frame->calledArgs) && null !== $frame->calledArgs[1]) {
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

        $restIndexArg = null;
        if (\array_key_exists(2, $frame->calledArgs) && null !== $frame->calledArgs[2]) {
            $restIndexArg = $frame->calledArgs[2];
            VmGetopt::validateRestIndexByRef($restIndexArg, 'getopt', 2);
        }

        $restIndex = 0;
        $parsed = GetoptEngine::parse(
            $frame->vmContext->cliRequestArgv,
            $shortOptions,
            $longOptions,
            $restIndex,
            null !== $restIndexArg
        );
        if (null !== $restIndexArg) {
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
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('getopt() expects between 1 and 3 arguments in this compiler build');
        }
        if ($argc >= 3) {
            throw new \LogicException('getopt() rest_index by-ref is VM-only in this compiler build (issue #3251)');
        }
        // Soft-null outside strict_types; strict → TypeError (#30358).
        // Early return after compile-time null TypeError — no getopt helper after abort
        // (AOT module verify: terminator mid-block; peer getservbyname #30281).
        if ($context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false))) {
            JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $args[0],
                'getopt',
                0,
                'short_options',
                'string',
                null,
                false
            );

            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }

        $longOptions = 2 === $argc ? self::resolveJitLongOptions($context, $args[1]) : [];

        return JitGetopt::invoke($context, $args[0], $longOptions);
    }

    /**
     * @return list<string>
     */
    private static function resolveJitLongOptions(Context $context, JITVariable $arg): array
    {
        if (JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            return [];
        }
        if (0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY) && \is_array($arg->compileTimeArray ?? null)) {
            $specs = [];
            foreach ($arg->compileTimeArray as $entry) {
                if (!\is_string($entry)) {
                    throw new \LogicException('getopt(): Argument #2 ($long_options) must be a list of strings');
                }
                $specs[] = $entry;
            }

            return $specs;
        }
        if (null === $context->runtime->vmContext) {
            throw new \LogicException('getopt() requires VM context for long_options lowering');
        }
        $phpVar = self::materializeJitArrayArg($context, $arg);
        if (null === $phpVar || Variable::TYPE_ARRAY !== $phpVar->type) {
            throw new \LogicException(
                'getopt(): Argument #2 ($long_options) must be a compile-time array literal in JIT/AOT'
            );
        }
        $specs = [];
        foreach ($phpVar->toArray()->iterate(true) as $entry) {
            $entry = $entry->resolveIndirect();
            $specs[] = VmString::coerceStringBuiltinArg($entry, 'getopt', 1, 'long_options');
        }

        return $specs;
    }

    private static function materializeJitArrayArg(Context $context, JITVariable $arg): ?Variable
    {
        if (null !== $arg->compileTimeConstantName) {
            $resolved = $context->runtime->vmContext->constantFetch($arg->compileTimeConstantName);
            if (null !== $resolved) {
                return $resolved->resolveIndirect();
            }
        }
        if (JITVariable::TYPE_STRING === $arg->type && null !== $arg->compileTimeString) {
            $v = new Variable();
            $v->string($arg->compileTimeString);

            return $v;
        }
        if (JITVariable::TYPE_VALUE !== $arg->type || null === $context->runtime->vm) {
            return null;
        }
        $frame = $context->runtime->vm->getTopFrame();
        if (null === $frame) {
            return null;
        }
        foreach ($frame->locals as $local) {
            if ($local->jitVariable === $arg) {
                return $local->resolveIndirect();
            }
        }

        return null;
    }
}
