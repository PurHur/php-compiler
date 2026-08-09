<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * str_getcsv() — parse a CSV line from a string (subset of PHP; issue #2391).
 *
 * VM: {@see VmCsv::parseLine()}; JIT/AOT: {@see JitStrGetcsv} via StringStrGetcsv + CsvJitHelper (#9444).
 */
final class str_getcsv extends Internal
{
    public function __construct()
    {
        parent::__construct('str_getcsv');
    }

    public function execute(Frame $frame): void
    {
        if (!isset($frame->calledArgs[0])) {
            throw new \LogicException('str_getcsv() requires at least a string argument in this compiler build');
        }
        foreach (\array_keys($frame->calledArgs) as $idx) {
            if ($idx < 0 || $idx > 3) {
                throw new \ArgumentCountError(\sprintf(
                    'str_getcsv() expects at most 4 arguments, %d given',
                    $idx + 1
                ));
            }
        }
        $input = VmString::trimFamilyStringArgForFrame($frame, 0, 'str_getcsv', 0, 'string');
        $separator = ',';
        $enclosure = '"';
        $escape = '\\';
        if (isset($frame->calledArgs[1])) {
            self::rejectNullOptionalString($frame, $frame->calledArgs[1], 1, 'separator');
            $separator = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'str_getcsv', 1, 'separator');
        }
        if (isset($frame->calledArgs[2])) {
            self::rejectNullOptionalString($frame, $frame->calledArgs[2], 2, 'enclosure');
            $enclosure = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'str_getcsv', 2, 'enclosure');
        }
        $escapeOmitted = !isset($frame->calledArgs[3]);
        if (!$escapeOmitted) {
            self::rejectNullOptionalString($frame, $frame->calledArgs[3], 3, 'escape');
            $escape = VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'str_getcsv', 3, 'escape');
        }
        // php-src: validate separator/enclosure before omitted-$escape DEP (#29383, file.c).
        VmCsvArg::validateStrGetcsvOptions($separator, $enclosure, $escape);
        if ($escapeOmitted) {
            // php-src 8.4+: omitted $escape → E_DEPRECATED (#21174, file.c).
            VmCsvArg::emitOmittedEscapeDeprecation($frame, 'str_getcsv');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $row = VmCsv::parseLine($input, $separator, $enclosure, $escape);
        $frame->returnVar->array(VmFs::csvRowToArray($row));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \LogicException('str_getcsv() requires at least a string argument in this compiler build');
        }
        if (\count($args) > 4) {
            throw new \LogicException('str_getcsv() expects at most 4 arguments');
        }
        $strPtr = $context->getTypeFromString('__string__*');
        $input = self::jitStringArg($context, $args[0], 0, 'string');
        $separator = $strPtr->constNull();
        $enclosure = $strPtr->constNull();
        $escape = $strPtr->constNull();
        if (isset($args[1]) && !NamedOptionalCallArgs::isOmittedOptional($args[1])) {
            self::rejectNullOptionalStringJit($context, $args[1], 1, 'separator');
            $separator = JitStringBuiltinArg::lower($context, $args[1], 'str_getcsv', 1, 'separator');
        }
        if (isset($args[2]) && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            self::rejectNullOptionalStringJit($context, $args[2], 2, 'enclosure');
            $enclosure = JitStringBuiltinArg::lower($context, $args[2], 'str_getcsv', 2, 'enclosure');
        }
        $escapeOmitted = !isset($args[3]) || NamedOptionalCallArgs::isOmittedOptional($args[3]);
        if (!$escapeOmitted) {
            self::rejectNullOptionalStringJit($context, $args[3], 3, 'escape');
            $escape = JitStringBuiltinArg::lower($context, $args[3], 'str_getcsv', 3, 'escape');
        }
        // php-src: validate before omitted-$escape DEP (#29383 / #24148).
        if (!JitCsvArg::validateStrGetcsvCall($context, ...$args)) {
            // Compile-time ValueError already emitted; return a dummy __value__* (fputcsv pattern).
            return $context->builder->pointerCast(
                $context->constantFromInteger(0, 'int64'),
                $context->getTypeFromString('__value__*')
            );
        }
        if ($escapeOmitted) {
            // php-src 8.4+: omitted $escape → E_DEPRECATED (#21174, file.c).
            VmCsvArg::emitJitOmittedEscapeDeprecation($context, 'str_getcsv');
        }

        return JitStrGetcsv::invoke($context, $input, $separator, $enclosure, $escape);
    }

    private static function jitStringArg(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName
    ): Value {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'str_getcsv',
                $argIndex,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'str_getcsv',
            $argIndex,
            $paramName
        );
    }

    /** php-src Z_PARAM_STR — null TypeError only under declare(strict_types=1) (#21734, file.c). */
    private static function rejectNullOptionalString(
        Frame $frame,
        Variable $var,
        int $argIndex,
        string $paramName
    ): void {
        if (!InternalStrictArg::isCallerStrict($frame)) {
            return;
        }
        if (Variable::TYPE_NULL === $var->resolveIndirect()->type) {
            throw new \TypeError(\sprintf(
                'str_getcsv(): Argument #%d ($%s) must be of type string, null given',
                $argIndex + 1,
                $paramName
            ));
        }
    }

    private static function rejectNullOptionalStringJit(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName
    ): void {
        if (!$context->callerStrictTypes) {
            return;
        }
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            ExceptionBridge::emitTypeErrorAndAbort($context, \sprintf(
                'str_getcsv(): Argument #%d ($%s) must be of type string, null given',
                $argIndex + 1,
                $paramName
            ));
        }
    }
}
