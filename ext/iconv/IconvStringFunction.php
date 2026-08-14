<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * Shared VM/JIT wiring for iconv string helper builtins (php-src ext/iconv/iconv.c; #6247).
 */
abstract class IconvStringFunction extends Internal
{
    public function call(Context $context, JITVariable ...$args): Value
    {
        $range = self::stringHelperArgcRange($this->getName());
        if (null !== $range && !$this->requireArgCountRangeJit($context, $args, $this->getName(), $range[0], $range[1])) {
            return JitIconvString::dummyAfterArgcAbort($context, $this->getName());
        }

        return JitIconvString::dispatch($context, $this->getName(), ...$args);
    }

    /**
     * php-src stub arities — excess uses Zend `at most` wording (#30891).
     *
     * @return array{0: int, 1: int}|null
     */
    protected static function stringHelperArgcRange(string $function): ?array
    {
        return match ($function) {
            'iconv_strlen' => [1, 2],
            'iconv_strpos', 'iconv_substr' => [2, 4],
            'iconv_strrpos' => [2, 3],
            default => null,
        };
    }

    /**
     * Z_PARAM_STR — non-strict null is E_DEPRECATED + '' on 8.4 (php-src iconv.c / #21197).
     */
    protected function coerceInputString(Frame $frame, int $index, string $param): string
    {
        return VmString::trimFamilyStringArgForFrame($frame, $index, $this->getName(), $index, $param);
    }

    protected function coerceEncoding(Frame $frame, int $index): string
    {
        return VmIconv::coerceOptionalEncodingArg(
            $frame->calledArgs[$index],
            $this->getName(),
            $index,
            'encoding',
            $frame
        );
    }

    protected function coerceOffset(Frame $frame, int $index): int
    {
        return VmMath::parseIntBuiltinArgForFrame($frame, $index, $this->getName(), $index + 1, 'offset');
    }

    protected function coerceLength(Frame $frame, int $index): ?int
    {
        $var = $frame->calledArgs[$index]->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }

        return VmMath::parseIntBuiltinArgForFrame($frame, $index, $this->getName(), $index + 1, 'length');
    }

    protected function writeIntOrFalse(Frame $frame, int|false $result): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            if (false === $result) {
                $ret->bool(false);

                return;
            }
            $ret->int($result);
        });
    }

    protected function writeStringOrFalse(Frame $frame, string|false $result): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            if (false === $result) {
                $ret->bool(false);

                return;
            }
            $ret->string($result);
        });
    }
}
