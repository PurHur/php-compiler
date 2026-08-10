<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\DateTimeZoneSupport;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * timezone_identifiers_list() — Olson timezone identifiers (ext/date/php_date.c, #3504).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(timezone_identifiers_list)
 */
final class timezone_identifiers_list extends Internal
{
    public function __construct()
    {
        parent::__construct('timezone_identifiers_list');
    }

    public function execute(Frame $frame): void
    {
        // Named countryCode alone leaves a hole at timezoneGroup (php-src php_date.stub.php; #25173).
        $maxIndex = -1;
        foreach (\array_keys($frame->calledArgs) as $index) {
            if (\is_int($index) && $index > $maxIndex) {
                $maxIndex = $index;
            }
        }
        $argc = $maxIndex + 1;
        if ($argc > 2) {
            throw new \ArgumentCountError(
                \sprintf('timezone_identifiers_list() expects at most 2 arguments, %d given', $argc)
            );
        }

        $timezoneGroup = DateTimeZoneSupport::GROUP_ALL;
        $countryCode = null;
        if (\array_key_exists(0, $frame->calledArgs) && null !== $frame->calledArgs[0]) {
            // php-src php_date.stub.php int $timezoneGroup — null TypeError under strict_types (#29844).
            $timezoneGroup = VmMath::parseIntBuiltinArgForFrame(
                $frame,
                0,
                'timezone_identifiers_list',
                1,
                'timezoneGroup'
            );
        }
        if (\array_key_exists(1, $frame->calledArgs) && null !== $frame->calledArgs[1]) {
            $countryArg = $frame->calledArgs[1]->resolveIndirect();
            if (EnumCaseSupport::isEnumCaseVariable($countryArg)) {
                throw new \TypeError(\sprintf(
                    'timezone_identifiers_list(): Argument #2 ($countryCode) must be of type ?string, %s given',
                    EnumCaseSupport::typeNameForVariable($countryArg)
                ));
            }
            if (Variable::TYPE_NULL === $countryArg->type) {
                $countryCode = null;
            } else {
                $countryCode = VmString::coerceStringBuiltinArg(
                    $frame->calledArgs[1],
                    'timezone_identifiers_list',
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

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitTimezoneIdentifiersList::invoke($context, ...$args);
    }
}
