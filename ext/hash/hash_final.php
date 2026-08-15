<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/**
 * hash_final() — finalize HashContext digest (php-src ext/hash/hash.c; issue #7174).
 *
 * Stub second param is `$binary` (php-src hash.stub.php); legacy InternalArgInfo
 * `raw_output` is overridden via BuiltinParamNames (#23586).
 * Excess argc → ArgumentCountError (#28315).
 */
final class hash_final extends HashFunction
{
    public function execute(Frame $frame): void
    {
        // php-src ext/hash/hash.stub.php — ArgumentCountError (#28315).
        $this->requireArgCountRange($frame, 'hash_final', 1, 2);
        $argc = \count($frame->calledArgs);
        $ctx = VmHashContext::requireHashContext($frame->calledArgs[0], 'hash_final', 1);
        $raw = false;
        if (2 === $argc) {
            // Z_PARAM_BOOL: caller strict_types → TypeError on null; else soft-null DEP+coerce (#31288).
            $raw = VmMath::parseBoolBuiltinArgForFrame($frame, 1, 'hash_final', 2, 'binary');
        }
        $digest = VmHashContext::final($ctx, $raw);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($digest): void {
            $ret->string($digest);
        });
    }
}
