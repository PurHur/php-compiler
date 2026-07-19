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
 * intltz_get_canonical_id() — procedural IntlTimeZone::getCanonicalID
 * (php-src timezone_methods.cpp / timezone.stub.php; #20859).
 */
final class intltz_get_canonical_id extends Internal
{
    public function __construct()
    {
        parent::__construct('intltz_get_canonical_id');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'intltz_get_canonical_id() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        $id = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'intltz_get_canonical_id',
            0,
            'zoneId'
        );
        $isSystemId = null;
        $canonical = VmIntlTimeZone::getCanonicalID($id, $isSystemId);
        if ($argc >= 2) {
            $out = $frame->calledArgs[1]->resolveIndirect();
            if (null === $isSystemId) {
                $out->null();
            } else {
                $out->bool($isSystemId);
            }
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $canonical) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($canonical);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intltz_get_canonical_id() is not implemented for JIT in this compiler build (issue #20859)');
    }
}