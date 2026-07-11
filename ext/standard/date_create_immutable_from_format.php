<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * date_create_immutable_from_format() — procedural DateTimeImmutable factory (#6172).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date_create_immutable_from_format)
 */
final class date_create_immutable_from_format extends Internal
{
    public function __construct()
    {
        parent::__construct('date_create_immutable_from_format');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'date_create_immutable_from_format() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar || null === $frame->vmContext) {
            return;
        }

        $format = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'date_create_immutable_from_format',
            0,
            'format'
        );
        $time = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'date_create_immutable_from_format',
            1,
            'datetime'
        );
        $timezone = null;
        if ($argc >= 3) {
            $tzVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $tzVar->type) {
                $timezone = DateTimeSupport::requireDateTimeZone(
                    $frame->calledArgs[2],
                    'date_create_immutable_from_format(): Argument #3 ($timezone)'
                );
            }
        }

        $created = DateTimeSupport::tryNewDateTimeImmutableFromFormatVariable(
            $frame->vmContext,
            $format,
            $time,
            $timezone
        );
        if (null === $created) {
            // php-src ext/date/php_date.c — false on failure; getLastErrors() only (#10010).
            BuiltinExecute::writeReturn($frame, static function (Variable $ret): void {
                $ret->bool(false);
            });

            return;
        }

        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($created): void {
            $ret->copyFrom($created);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitDateCreateFromFormat::invoke($context, true, ...$args);
    }
}
