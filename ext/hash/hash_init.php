<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/**
 * hash_init() — incremental digest context (php-src ext/hash/hash.c; issue #7174).
 */
final class hash_init extends HashFunction
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('hash_init() requires exactly one argument in this compiler build');
        }
        // Z_PARAM_STR $algo — non-strict null is E_DEPRECATED + '' then ValueError (#21572).
        $algo = VmString::trimFamilyStringArgForFrame($frame, 0, 'hash_init', 0, 'algo');
        $ctx = VmHashContext::init(VmReflection::requireContext($frame), $algo);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ctx): void {
            $ret->copyFrom($ctx);
        });
    }
}
