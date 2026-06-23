<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** touch() — VM via VmFs; JIT/AOT via __compiler_touch (libc utime). */
final class touch_ extends Internal
{
    private const FUNCTION = 'touch';

    public function __construct()
    {
        parent::__construct(self::FUNCTION);
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('touch() requires one to three arguments in this compiler build');
        }
        $path = VmFilestatArg::coerceFilenameArg($frame->calledArgs[0], self::FUNCTION);
        $mtime = null;
        if ($argc >= 2) {
            $mtime = self::parseNullableLong($frame->calledArgs[1]->resolveIndirect(), 2, 'mtime');
        }
        $atime = null;
        if (3 === $argc) {
            $atime = self::parseNullableLong($frame->calledArgs[2]->resolveIndirect(), 3, 'atime');
        }
        $ok = VmFs::touch($path, $mtime, $atime);
        if (!$ok) {
            VmFilestatFailure::warnTouchCreateFailed($frame, $path);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('touch() requires one to three arguments in this compiler build');
        }
        $path = JitFilestatArg::lowerFilename($context, $args[0], self::FUNCTION);
        $i64 = $context->getTypeFromString('int64');
        $sentinel = $i64->constInt(-1, true);
        $mtime = $sentinel;
        if ($argc >= 2) {
            $mtime = JitTouch::lowerNullableLong($context, $args[1], 2, 'mtime', $sentinel);
        }
        $atime = $sentinel;
        if (3 === $argc) {
            $atime = JitTouch::lowerNullableLong($context, $args[2], 3, 'atime', $sentinel);
        }

        return JitTouch::invoke($context, $path, $mtime, $atime);
    }

    /**
     * php-src: Z_PARAM_LONG_OR_NULL (ext/standard/filestat.c — php_touch).
     */
    private static function parseNullableLong(Variable $var, int $argIndex, string $paramName): ?int
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            throw new \TypeError(self::nullableLongTypeError($argIndex, $paramName, 'array'));
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            throw new \TypeError(self::nullableLongTypeError($argIndex, $paramName, 'object'));
        }
        switch ($var->type) {
            case Variable::TYPE_INTEGER:
                return $var->toInt();
            case Variable::TYPE_BOOLEAN:
                return $var->toBool() ? 1 : 0;
            case Variable::TYPE_FLOAT:
                return (int) $var->toFloat();
            case Variable::TYPE_STRING:
                $s = $var->toString();
                if ('' === $s || !is_numeric($s)) {
                    throw new \TypeError(self::nullableLongTypeError($argIndex, $paramName, 'string'));
                }

                return (int) $s;
            default:
                throw new \TypeError(
                    self::nullableLongTypeError($argIndex, $paramName, self::vmTypeName($var->type))
                );
        }
    }

    private static function nullableLongTypeError(int $argIndex, string $paramName, string $given): string
    {
        return sprintf(
            '%s(): Argument #%d ($%s) must be of type ?int, %s given',
            self::FUNCTION,
            $argIndex,
            $paramName,
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
