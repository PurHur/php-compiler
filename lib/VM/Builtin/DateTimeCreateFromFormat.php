<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\Variable;

/** DateTime::createFromFormat() — VM (#9921, ext/date/php_datetime.c). */
final class DateTimeCreateFromFormat extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('createFromFormat');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('DateTime::createFromFormat() requires VM context');
        }
        // Static factory: calledArgs has no $this — Zend stub arity before coercion (#30898 sibling).
        $this->requireArgCountRange($frame, 'DateTime::createFromFormat', 2, 3);
        $argc = \count($frame->calledArgs);
        // Static factory: calledArgs has no $this — Zend stub indices (php-src php_date.stub.php, #29269/#29830).
        // Z_PARAM_STR — caller strict_types → TypeError on null $format/$datetime.
        $format = VmString::stringBuiltinArgForFrame(
            $frame,
            0,
            'DateTime::createFromFormat',
            0,
            'format'
        );
        $time = VmString::stringBuiltinArgForFrame(
            $frame,
            1,
            'DateTime::createFromFormat',
            1,
            'datetime'
        );
        $timezone = null;
        if ($argc >= 3) {
            $tzVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $tzVar->type) {
                $timezone = DateTimeSupport::requireDateTimeZone(
                    $frame->calledArgs[2],
                    'DateTime::createFromFormat() timezone'
                );
            }
        }

        $created = DateTimeSupport::tryNewDateTimeFromFormatVariable(
            $frame->vmContext,
            $format,
            $time,
            $timezone
        );
        if (null === $created) {
            // php-src ext/date/php_datetime.c — false on failure; errors via getLastErrors(), no E_WARNING (#10010).
            BuiltinExecute::writeReturn($frame, static function (Variable $ret): void {
                $ret->bool(false);
            });

            return;
        }

        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($created): void {
            $ret->copyFrom($created);
        });
    }
}
