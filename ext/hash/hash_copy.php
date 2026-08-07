<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/**
 * hash_copy() — clone live HashContext (php-src ext/hash/hash.c; issue #7174).
 *
 * Excess argc → ArgumentCountError (#28315).
 */
final class hash_copy extends HashFunction
{
    public function execute(Frame $frame): void
    {
        // php-src ext/hash/hash.stub.php — ArgumentCountError (#28315).
        $this->requireExactArgCount($frame, 'hash_copy', 1);
        $ctx = VmHashContext::requireHashContext($frame->calledArgs[0], 'hash_copy', 1);
        $clone = VmHashContext::copy($ctx);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($clone): void {
            $ret->copyFrom($clone);
        });
    }
}
