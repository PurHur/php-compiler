<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * parse_ini_file() — INI file parser (ext/standard/basic_functions.c; issue #3263 / #30756).
 *
 * JIT/AOT: compile-time path materializes via {@see VmParseIni::parseString} + {@see JitParseIniMaterializer};
 * runtime path reads then {@see JitParseIni::parseRuntimeFile} (same NestedJIT helper as parse_ini_string).
 */
final class parse_ini_file extends Internal
{
    public function __construct()
    {
        parent::__construct('parse_ini_file');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'parse_ini_file() expects between 1 and 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $filename = InternalStrictArg::resolveCoercibleStringArg($frame, 0, 'parse_ini_file', 'filename', false);
        VmString::rejectEmptyBuiltinStringArg($filename, 'parse_ini_file', 0, 'filename', true);
        $processSections = false;
        $scannerMode = ParseIniEngine::SCANNER_NORMAL;
        if (isset($frame->calledArgs[1])) {
            $processSections = VmParseIni::resolveProcessSections($frame, 1, 'parse_ini_file');
        }
        if (isset($frame->calledArgs[2])) {
            $scannerMode = VmParseIni::resolveScannerMode($frame, 2, 'parse_ini_file');
        }
        VmParseIni::assignParsedResult(
            $frame->returnVar,
            VmParseIni::parseFile($frame, $filename, $processSections, $scannerMode)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('parse_ini_file() expects between 1 and 3 arguments in this compiler build');
        }
        // Strict null TypeErrors first — trailing null can make prior bool args non-ConstantInt (#31264).
        if ($context->callerStrictTypes) {
            if (isset($args[1])
                && (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false))) {
                ExceptionBridge::emitTypeErrorAndAbort(
                    $context,
                    'parse_ini_file(): Argument #2 ($process_sections) must be of type bool, null given'
                );
                BasicBlockHelper::ensureOpenInsertBlock($context, 'parse_ini_file_null_sections_te_cont');
                $slot = JitValueBox::alloc($context);

                return JitValueBox::pointer($context, $slot);
            }
            if (isset($args[2])
                && (JITVariable::TYPE_NULL === $args[2]->type || ($args[2]->isNullConstant ?? false))) {
                JitIntdiv::lowerIntBuiltinArgForCaller(
                    $context,
                    $args[2],
                    'parse_ini_file',
                    3,
                    'scanner_mode'
                );
                BasicBlockHelper::ensureOpenInsertBlock($context, 'parse_ini_file_null_scanner_te_cont');
                $slot = JitValueBox::alloc($context);

                return JitValueBox::pointer($context, $slot);
            }
        }
        $processSections = false;
        if (isset($args[1])) {
            // Soft null → DEP+false (#31264 / peer get_browser #31289).
            if (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)) {
                JitBoolArg::lowerCoerceZParamBool(
                    $context,
                    $args[1],
                    'parse_ini_file',
                    'process_sections',
                    2
                );
                $processSections = false;
            } else {
                $sections = self::compileTimeBool($context, $args[1]);
                if (null === $sections) {
                    throw new \LogicException('parse_ini_file() requires compile-time process_sections in this compiler build');
                }
                $processSections = $sections;
            }
        }
        $scannerMode = ParseIniEngine::SCANNER_NORMAL;
        if (isset($args[2])) {
            // Soft null → DEP+0 (#31264 / peer metaphone #31230).
            if (JITVariable::TYPE_NULL === $args[2]->type || ($args[2]->isNullConstant ?? false)) {
                JitIntdiv::lowerIntBuiltinArgForCaller(
                    $context,
                    $args[2],
                    'parse_ini_file',
                    3,
                    'scanner_mode'
                );
                $scannerMode = ParseIniEngine::SCANNER_NORMAL;
            } else {
                $mode = self::compileTimeInt($context, $args[2]);
                if (null === $mode) {
                    throw new \LogicException('parse_ini_file() requires compile-time scanner_mode in this compiler build');
                }
                $scannerMode = $mode;
            }
        }
        $emptyMsg = VmString::emptyStringArgValueErrorMessageCannot('parse_ini_file', 0, 'filename');
        $pathStr = JitStreamPath::lowerNonEmptyPath(
            $context,
            $args[0],
            'parse_ini_file',
            0,
            'filename',
            $emptyMsg
        );
        $literal = JitStringArg::compileTimeLiteral($args[0]);
        if (null !== $literal && '' !== $literal) {
            $contents = VmFsReadNative::read($literal);
            if (false !== $contents) {
                $parsed = VmParseIni::parseString($contents, $processSections, $scannerMode);
                if (false === $parsed) {
                    $slot = JitValueBox::alloc($context);
                    $ptr = JitValueBox::pointer($context, $slot);
                    $context->builder->call(
                        $context->lookupFunction('__value__writeBool'),
                        $ptr,
                        $context->constantFromBool(false)
                    );

                    return $ptr;
                }
                $ht = JitParseIniMaterializer::materializeParsed($context, $parsed);
                $slot = JitValueBox::alloc($context);
                $ptr = JitValueBox::pointer($context, $slot);
                $context->builder->call($context->lookupFunction('__value__writeHashtable'), $ptr, $ht);

                return $ptr;
            }
        }
        if ($processSections || ParseIniEngine::SCANNER_NORMAL !== $scannerMode) {
            throw new \LogicException(
                'parse_ini_file() requires compile-time filename for process_sections/scanner_mode in this compiler build'
            );
        }

        return JitParseIni::parseRuntimeFile($context, $pathStr, $processSections, $scannerMode);
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
