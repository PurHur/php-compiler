<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * parse_ini_string() — INI string parser (ext/standard/basic_functions.c; issue #3263).
 */
final class parse_ini_string extends Internal
{
    public function __construct()
    {
        parent::__construct('parse_ini_string');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'parse_ini_string() expects between 1 and 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ini = VmString::trimFamilyStringArgForFrame($frame, 0, 'parse_ini_string', 0, 'ini_string');
        $processSections = false;
        $scannerMode = ParseIniEngine::SCANNER_NORMAL;
        if (isset($frame->calledArgs[1])) {
            $processSections = VmParseIni::resolveProcessSections($frame->calledArgs[1], 'parse_ini_string');
        }
        if (isset($frame->calledArgs[2])) {
            $scannerMode = VmParseIni::resolveScannerMode($frame->calledArgs[2], 'parse_ini_string');
        }
        VmParseIni::assignParsedResult(
            $frame->returnVar,
            VmParseIni::parseString($ini, $processSections, $scannerMode, $frame)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('parse_ini_string() expects between 1 and 3 arguments in this compiler build');
        }
        $processSections = false;
        if (isset($args[1])) {
            $sections = self::compileTimeBool($context, $args[1]);
            if (null === $sections) {
                throw new \LogicException('parse_ini_string() requires compile-time process_sections in this compiler build');
            }
            $processSections = $sections;
        }
        $scannerMode = ParseIniEngine::SCANNER_NORMAL;
        if (isset($args[2])) {
            $mode = self::compileTimeInt($context, $args[2]);
            if (null === $mode) {
                throw new \LogicException('parse_ini_string() requires compile-time scanner_mode in this compiler build');
            }
            $scannerMode = $mode;
        }
        if (JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant) {
            if ($context->callerStrictTypes) {
                JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'parse_ini_string', 0, 'ini_string');
                $slot = JitValueBox::alloc($context);

                return JitValueBox::pointer($context, $slot);
            }
            JitStringBuiltinArg::emitNullStringParamDeprecation($context, 'parse_ini_string', 0, 'ini_string');
            $literal = '';
        } else {
            $literal = JitStringArg::compileTimeLiteral($args[0]);
            if (null === $literal) {
                // Runtime subject — NestedJIT NORMAL/flat via native HT (#26909).
                // process_sections / non-NORMAL still need compile-time materialize.
                if ($processSections || ParseIniEngine::SCANNER_NORMAL !== $scannerMode) {
                    throw new \LogicException(
                        'parse_ini_string() requires compile-time ini_string for process_sections/scanner_mode in this compiler build'
                    );
                }

                return JitParseIni::parseRuntime($context, $args[0], $processSections, $scannerMode);
            }
        }
        $parsed = VmParseIni::parseString($literal, $processSections, $scannerMode);
        if (false === $parsed) {
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $context->builder->call($context->lookupFunction('__value__writeBool'), $ptr, $context->constantFromBool(false));

            return $ptr;
        }
        $ht = JitParseIniMaterializer::materializeParsed($context, $parsed);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $ptr, $ht);

        return $ptr;
    }

    private static function compileTimeBool(Context $context, JITVariable $var): ?bool
    {
        if (JITVariable::KIND_VALUE !== $var->kind) {
            return null;
        }
        $lib = $context->llvm->lib;
        if (JITVariable::TYPE_NATIVE_BOOL === $var->type
            && null !== $lib->LLVMIsAConstantInt($var->value->value)) {
            return 0 !== (int) $lib->LLVMConstIntGetZExtValue($var->value->value);
        }
        if (JITVariable::TYPE_NATIVE_LONG === $var->type
            && null !== $lib->LLVMIsAConstantInt($var->value->value)) {
            $raw = (int) $lib->LLVMConstIntGetZExtValue($var->value->value);

            return 0 !== $raw;
        }

        return null;
    }

    private static function compileTimeInt(Context $context, JITVariable $var): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG === $var->type && JITVariable::KIND_VALUE === $var->kind) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($var->value->value)) {
                return (int) $lib->LLVMConstIntGetSExtValue($var->value->value);
            }
        }

        return null;
    }
}
