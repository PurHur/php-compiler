<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/**
 * IntlDateFormatter::formatObject() — php-src datefmt_format_object (#20813).
 */
final class IntlDateFormatterFormatObject extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('formatObject');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'IntlDateFormatter::formatObject() expects between 1 and 3 arguments, %d given',
                $argc
            ));
        }
        $datetime = $frame->calledArgs[0];
        $dtVar = $datetime->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $dtVar->type) {
            throw new \TypeError(\sprintf(
                'IntlDateFormatter::formatObject(): Argument #1 ($datetime) must be of type object, %s given',
                ReflectionSupport::valueTypeLabelPublic($dtVar)
            ));
        }
        $format = null;
        if ($argc >= 2) {
            $format = self::coerceFormatArg($frame->calledArgs[1], 'IntlDateFormatter::formatObject', 2);
        }
        $locale = null;
        if ($argc >= 3) {
            $localeVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $localeVar->type) {
                $locale = VmString::coerceStringBuiltinArg(
                    $localeVar,
                    'IntlDateFormatter::formatObject',
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

    /**
     * @return array{0: int, 1: int}|int|string|null
     */
    public static function coerceFormatArg(Variable $var, string $function, int $position): array|int|string|null
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            return $var->toInt();
        }
        if (Variable::TYPE_STRING === $var->type) {
            return $var->toString();
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            $ht = $var->toArray();
            $vals = [];
            for ($i = 0; $i < 2; ++$i) {
                $el = $ht->findIndex($i);
                if (null === $el) {
                    IntlError::set(
                        IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                        'datefmt_format_object: bad format; if array, it must have two elements: U_ILLEGAL_ARGUMENT_ERROR'
                    );

                    return [];
                }
                $vals[] = VmIntlDateFormatter::coerceIntArg($el, $function, $position, 'format');
            }

            return $vals;
        }
        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($format) must be of type array|int|string|null, %s given',
            $function,
            $position,
            ReflectionSupport::valueTypeLabelPublic($var)
        ));
    }
}
