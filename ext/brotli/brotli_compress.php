<?php

declare(strict_types=1);

namespace PHPCompiler\ext\brotli;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStrictIntArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** brotli_compress() — libbrotli via FFI (kjdev/php-ext-brotli; issue #6814). */
final class brotli_compress extends Internal
{
    public function __construct()
    {
        parent::__construct('brotli_compress');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('brotli_compress() expects one to three arguments in this compiler build');
        }
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'brotli_compress', 0, 'data');
        $level = VmBrotliNative::DEFAULT_QUALITY;
        if ($argc >= 2) {
            $level = self::parseIntArg($frame->calledArgs[1], 'brotli_compress', 1, 'level');
        }
        $mode = VmBrotliNative::MODE_GENERIC;
        if (3 === $argc) {
            $mode = self::parseIntArg($frame->calledArgs[2], 'brotli_compress', 2, 'mode');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmBrotliNative::compress($data, $level, $mode);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('brotli_compress() expects one to three arguments in this compiler build');
        }
        $level = JitBrotli::defaultLevel($context);
        $mode = JitBrotli::defaultMode($context);
        if ($argc >= 2) {
            $level = JitStrictIntArg::lower($context, $args[1], 'brotli_compress', 1, 'level');
        }
        if (3 === $argc) {
            $mode = JitStrictIntArg::lower($context, $args[2], 'brotli_compress', 2, 'mode');
        }

        return JitBrotli::compress(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'brotli_compress', 0, 'data'),
            $level,
            $mode
        );
    }

    private static function parseIntArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName,
    ): int {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type int, %s given',
                $function,
                $argIndex + 1,
                $paramName,
                match ($var->type) {
                    Variable::TYPE_NULL => 'null',
                    Variable::TYPE_BOOLEAN => 'bool',
                    Variable::TYPE_DOUBLE => 'float',
                    Variable::TYPE_STRING => 'string',
                    Variable::TYPE_ARRAY => 'array',
                    Variable::TYPE_OBJECT => $var->toObject()->class->name,
                    Variable::TYPE_RESOURCE => 'resource',
                    default => 'mixed',
                }
            ));
        }

        return $var->toInt();
    }
}
