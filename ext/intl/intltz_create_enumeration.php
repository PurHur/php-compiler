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
 * intltz_create_enumeration() — procedural IntlTimeZone::createEnumeration
 * (php-src timezone_methods.cpp / timezone.stub.php; #20925).
 * Returns array in v1 (IntlIterator tracked under #20909).
 */
final class intltz_create_enumeration extends Internal
{
    public function __construct()
    {
        parent::__construct('intltz_create_enumeration');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(\sprintf(
                'intltz_create_enumeration() expects at most 1 argument, %d given',
                $argc
            ));
        }
        $countryOrZone = null;
        if (1 === $argc) {
            $arg = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_NULL !== $arg->type) {
                if (Variable::TYPE_INTEGER !== $arg->type) {
                    $countryOrZone = VmString::coerceStringBuiltinArg(
                        $arg,
                        'intltz_create_enumeration',
                        0,
                        'countryOrZoneId'
                    );
                }
            }
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(VmIntlTimeZone::createEnumeration($countryOrZone));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intltz_create_enumeration() is not implemented for JIT in this compiler build (issue #20925)');
    }
}