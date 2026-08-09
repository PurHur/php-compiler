<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** fgetcsv() — VM via VmFs; JIT/AOT via StringFgetcsvJit (issue #1192, #6750). */
final class fgetcsv extends Internal
{
    public function __construct()
    {
        parent::__construct('fgetcsv');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/file.c — ArgumentCountError (#25407).
        $this->requireArgCountRange($frame, 'fgetcsv', 1, 5);
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $handle = VmStreamArg::requireStreamHandle($handleVar, 'fgetcsv');
        $length = null;
        if (isset($frame->calledArgs[1])) {
            $length = self::parseLengthArg($frame->calledArgs[1]->resolveIndirect());
        }
        $separator = ',';
        $enclosure = '"';
        $escape = '\\';
        if (isset($frame->calledArgs[2])) {
            $separator = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'fgetcsv', 2, 'separator');
        }
        if (isset($frame->calledArgs[3])) {
            $enclosure = VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'fgetcsv', 3, 'enclosure');
        }
        $escapeOmitted = !isset($frame->calledArgs[4]);
        if (!$escapeOmitted) {
            $escape = VmString::coerceStringBuiltinArg($frame->calledArgs[4], 'fgetcsv', 4, 'escape');
        }
        // php-src: validate separator/enclosure before omitted-$escape DEP (#29383, file.c).
        VmCsvArg::validateFgetcsvOptions($separator, $enclosure, $escape);
        if ($escapeOmitted) {
            // php-src 8.4+: omitted $escape → E_DEPRECATED (#21179, file.c).
            VmCsvArg::emitOmittedEscapeDeprecation($frame, 'fgetcsv');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $row = VmFs::fgetcsv($handle, $length, $separator, $enclosure, $escape);
        if (false === $row) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array(VmFs::csvRowToArray($row));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireArgCountRangeJit($context, $args, 'fgetcsv', 1, 5)) {
            return $context->builder->pointerCast(
                $context->constantFromInteger(0, 'int64'),
                $context->getTypeFromString('__value__*')
            );
        }
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], 'fgetcsv() handle'),
            $i64
        );
        $length = $i64->constInt(-1, true);
        if (isset($args[1]) && !NamedOptionalCallArgs::isOmittedOptional($args[1])) {
            if (JITVariable::TYPE_NULL !== $args[1]->type) {
                $length = $context->builder->truncOrBitCast(
                    JitLongArg::lower($context, $args[1], 'fgetcsv() length'),
                    $i64
                );
            }
        }
        $separator = $strPtr->constNull();
        $enclosure = $strPtr->constNull();
        $escape = $strPtr->constNull();
        if (isset($args[2]) && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            $separator = JitStringBuiltinArg::lower($context, $args[2], 'fgetcsv', 2, 'separator');
        }
        if (isset($args[3]) && !NamedOptionalCallArgs::isOmittedOptional($args[3])) {
            $enclosure = JitStringBuiltinArg::lower($context, $args[3], 'fgetcsv', 3, 'enclosure');
        }
        $escapeOmitted = !isset($args[4]) || NamedOptionalCallArgs::isOmittedOptional($args[4]);
        if (!$escapeOmitted) {
            $escape = JitStringBuiltinArg::lower($context, $args[4], 'fgetcsv', 4, 'escape');
        }
        // php-src: validate before omitted-$escape DEP (#29383).
        if (!JitCsvArg::validateFgetcsvCall($context, ...$args)) {
            return $context->builder->pointerCast(
                $context->constantFromInteger(0, 'int64'),
                $context->getTypeFromString('__value__*')
            );
        }
        if ($escapeOmitted) {
            // php-src 8.4+: omitted $escape → E_DEPRECATED (#21179, file.c).
            VmCsvArg::emitJitOmittedEscapeDeprecation($context, 'fgetcsv');
        }

        return JitFgetcsv::invoke($context, $handle, $length, $separator, $enclosure, $escape);
    }

    /**
     * php-src: Z_PARAM_LONG for $length — int, float, and numeric-string coerce; 0 → unlimited.
     *
     * @throws \TypeError
     * @throws \ValueError
     */
    private static function parseLengthArg(Variable $var): ?int
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(self::lengthTypeError(EnumCaseSupport::typeNameForVariable($var)));
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            throw new \TypeError(self::lengthTypeError('array'));
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            throw new \TypeError(self::lengthTypeError('object'));
        }
        switch ($var->type) {
            case Variable::TYPE_NULL:
                return null;
            case Variable::TYPE_INTEGER:
                $length = $var->toInt();
                break;
            case Variable::TYPE_BOOLEAN:
                $length = $var->toBool() ? 1 : 0;
                break;
            case Variable::TYPE_FLOAT:
                $f = $var->toFloat();
                if (!\is_finite($f)) {
                    throw new \TypeError(self::lengthTypeError('float'));
                }
                $length = (int) $f;
                break;
            case Variable::TYPE_STRING:
                $s = $var->toString();
                if ('' === $s || !is_numeric($s)) {
                    throw new \TypeError(self::lengthTypeError('string'));
                }
                $length = (int) $s;
                break;
            default:
                throw new \TypeError(self::lengthTypeError(self::vmTypeName($var->type)));
        }
        if ($length < 0) {
            throw new \ValueError(
                'fgetcsv(): Argument #2 ($length) must be between 0 and 9223372036854775806'
            );
        }
        if (0 === $length) {
            return null;
        }

        return $length;
    }

    private static function lengthTypeError(string $given): string
    {
        return sprintf(
            'fgetcsv(): Argument #2 ($length) must be of type ?int, %s given',
            $given
        );
    }

    private static function vmTypeName(int $type): string
    {
        return match ($type) {
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            Variable::TYPE_RESOURCE => 'resource',
            default => 'mixed',
        };
    }
}
