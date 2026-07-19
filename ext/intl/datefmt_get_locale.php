<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** datefmt_get_locale() — procedural IntlDateFormatter::getLocale (#20860). */
final class datefmt_get_locale extends Internal
{
    public function __construct()
    {
        parent::__construct('datefmt_get_locale');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'datefmt_get_locale() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlDateFormatter::isFormatterObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                'datefmt_get_locale(): Argument #1 ($formatter) must be of type IntlDateFormatter, %s given',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $type = $argc >= 2
            ? VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'datefmt_get_locale', 2, 'type')
            : VmIntlDateFormatter::ULOC_ACTUAL_LOCALE;
        $result = VmIntlDateFormatter::getLocale($receiver->toObject(), $type);
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
        throw new \Error('datefmt_get_locale() is not implemented for JIT in this compiler build (issue #20860)');
    }
}
