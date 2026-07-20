<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\DateTimeSupport;
use PHPLLVM\Value;

/**
 * date_format() — procedural DateTimeInterface::format wrapper (ext/date/php_date.c, #9219).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date_format)
 * Soft-null $format on 8.4 — Zend deprecate+coerce (#21536, reverts #20693 TypeError).
 */
final class date_format extends Internal
{
    public function __construct()
    {
        parent::__construct('date_format');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                \sprintf('date_format() expects exactly 2 arguments, %d given', $argc)
            );
        }
        $datetime = DateTimeSupport::requireDateTimeInterface(
            $frame->calledArgs[0],
            'date_format(): Argument #1 ($object)',
            $frame->vmContext,
            1,
            'object'
        );
        // Soft-null on 8.4 — Zend deprecate+coerce (#21536, reverts #20693 TypeError).
        $format = VmString::trimFamilyStringArgForFrame($frame, 1, 'date_format', 1, 'format');
        $formatted = DateTimeSupport::format($datetime, $format);
        BuiltinExecute::writeReturn($frame, static function ($ret) use ($formatted): void {
            $ret->string($formatted);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('date_format() expects exactly 2 arguments');
        }

        // Same lowering as DateTime::format — soft-null on 8.4 (#21536).
        return \PHPCompiler\VM\DateTimeFormatJitHelper::compileFormat(
            $context,
            $args[0],
            $args[1],
            'date_format',
            1
        );
    }
}
