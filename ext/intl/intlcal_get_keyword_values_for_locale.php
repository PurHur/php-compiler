<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * intlcal_get_keyword_values_for_locale() — procedural IntlCalendar::getKeywordValuesForLocale
 * (php-src calendar_methods.c / calendar.stub.php; #20896).
 */
final class intlcal_get_keyword_values_for_locale extends Internal
{
    public function __construct()
    {
        parent::__construct('intlcal_get_keyword_values_for_locale');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'intlcal_get_keyword_values_for_locale() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        $keyword = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'intlcal_get_keyword_values_for_locale',
            0,
            'key'
        );
        $locale = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'intlcal_get_keyword_values_for_locale',
            1,
            'locale'
        );
        $onlyCommonVar = $frame->calledArgs[2]->resolveIndirect();
        $onlyCommon = Variable::TYPE_NULL !== $onlyCommonVar->type && $onlyCommonVar->toBool();
        $result = VmIntlCalendar::getKeywordValuesForLocale($keyword, $locale, $onlyCommon);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intlcal_get_keyword_values_for_locale() is not implemented for JIT in this compiler build (issue #20896)');
    }
}