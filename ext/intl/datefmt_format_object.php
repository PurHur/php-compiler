<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** datefmt_format_object() — procedural IntlDateFormatter::formatObject (#20813). */
final class datefmt_format_object extends Internal
{
    public function __construct()
    {
        parent::__construct('datefmt_format_object');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'datefmt_format_object() expects between 1 and 3 arguments, %d given',
                $argc
            ));
        }
        $datetime = $frame->calledArgs[0];
        $dtVar = $datetime->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $dtVar->type) {
            throw new \TypeError(\sprintf(
                'datefmt_format_object(): Argument #1 ($datetime) must be of type object, %s given',
                ReflectionSupport::valueTypeLabelPublic($dtVar)
            ));
        }
        $format = null;
        if ($argc >= 2) {
            $format = IntlDateFormatterFormatObject::coerceFormatArg(
                $frame->calledArgs[1],
                'datefmt_format_object',
                2
            );
        }
        $locale = null;
        if ($argc >= 3) {
            $localeVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $localeVar->type) {
                $locale = VmString::coerceStringBuiltinArg(
                    $localeVar,
                    'datefmt_format_object',
                    3,
                    'locale'
                );
            }
        }
        $result = VmIntlDateFormatter::formatObject($frame->vmContext, $datetime, $format, $locale);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('datefmt_format_object() is not implemented for JIT in this compiler build (issue #20813)');
    }
}
