<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmDateTimeNative;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\DateTimeZoneSupport;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/**
 * DateTimeZone::listIdentifiers() — OOP twin of timezone_identifiers_list() (#6198, #3504).
 *
 * php-src: ext/date/php_datetime.c — PHP_METHOD(DateTimeZone, listIdentifiers)
 */
final class DateTimeZoneListIdentifiers extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('listIdentifiers');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 2) {
            throw new \ArgumentCountError(
                \sprintf('DateTimeZone::listIdentifiers() expects at most 2 arguments, %d given', $argc)
            );
        }

        $timezoneGroup = DateTimeZoneSupport::GROUP_ALL;
        $countryCode = null;
        if ($argc >= 1) {
            // php-src php_date.stub.php int $timezoneGroup — null TypeError under strict_types (#29844).
            $timezoneGroup = VmMath::parseIntBuiltinArgForFrame(
                $frame,
                0,
                'DateTimeZone::listIdentifiers',
                1,
                'timezoneGroup'
            );
        }
        if ($argc >= 2) {
            $countryArg = $frame->calledArgs[1]->resolveIndirect();
            if (EnumCaseSupport::isEnumCaseVariable($countryArg)) {
                throw new \TypeError(\sprintf(
                    'DateTimeZone::listIdentifiers(): Argument #2 ($countryCode) must be of type ?string, %s given',
                    EnumCaseSupport::typeNameForVariable($countryArg)
                ));
            }
            if (Variable::TYPE_NULL === $countryArg->type) {
                $countryCode = null;
            } else {
                $countryCode = VmString::coerceStringBuiltinArg(
                    $frame->calledArgs[1],
                    'DateTimeZone::listIdentifiers',
                    2,
                    'countryCode'
                );
            }
        }

        $identifiers = VmDateTimeNative::timezoneIdentifiersList($timezoneGroup, $countryCode);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($identifiers): void {
            $ret->array(VmFs::stringListToArray($identifiers));
        });
    }
}
