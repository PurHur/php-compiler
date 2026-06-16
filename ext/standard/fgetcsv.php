<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
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
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 5) {
            throw new \LogicException('fgetcsv() requires one to five arguments in this compiler build');
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $handle = VmStreamArg::requireStreamHandle($handleVar, 'fgetcsv');
        $length = null;
        if ($argc >= 2) {
            $length = self::parseLengthArg($frame->calledArgs[1]->resolveIndirect());
        }
        if (null === $frame->returnVar) {
            return;
        }
        $separator = ',';
        $enclosure = '"';
        $escape = '\\';
        if ($argc >= 3) {
            $separator = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'fgetcsv', 2, 'separator');
        }
        if ($argc >= 4) {
            $enclosure = VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'fgetcsv', 3, 'enclosure');
        }
        if ($argc >= 5) {
            $escape = VmString::coerceStringBuiltinArg($frame->calledArgs[4], 'fgetcsv', 4, 'escape');
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
        $argc = \count($args);
        if ($argc < 1 || $argc > 5) {
            throw new \LogicException('fgetcsv() requires one to five arguments in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], 'fgetcsv() handle'),
            $i64
        );
        $length = $i64->constInt(-1, true);
        if ($argc >= 2) {
            $length = $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[1], 'fgetcsv() length'),
                $i64
            );
        }
        $separator = $strPtr->constNull();
        $enclosure = $strPtr->constNull();
        $escape = $strPtr->constNull();
        if ($argc >= 3) {
            $separator = JitStringBuiltinArg::lower($context, $args[2], 'fgetcsv', 2, 'separator');
        }
        if ($argc >= 4) {
            $enclosure = JitStringBuiltinArg::lower($context, $args[3], 'fgetcsv', 3, 'enclosure');
        }
        if ($argc >= 5) {
            $escape = JitStringBuiltinArg::lower($context, $args[4], 'fgetcsv', 4, 'escape');
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
