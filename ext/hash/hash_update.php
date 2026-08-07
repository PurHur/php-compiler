<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/**
 * hash_update() — append data to HashContext (php-src ext/hash/hash.c; #7174, #21557).
 *
 * Excess argc → ArgumentCountError (#28315).
 */
final class hash_update extends HashFunction
{
    public function execute(Frame $frame): void
    {
        // php-src ext/hash/hash.stub.php — ArgumentCountError (#28315).
        $this->requireExactArgCount($frame, 'hash_update', 2);
        $ctx = VmHashContext::requireHashContext($frame->calledArgs[0], 'hash_update', 1);
        // Z_PARAM_STR $data — non-strict null is E_DEPRECATED + '' on 8.4 (#21557, reverts #20195).
        $data = VmString::trimFamilyStringArgForFrame($frame, 1, 'hash_update', 1, 'data');
        VmHashContext::update($ctx, $data);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret): void {
            $ret->bool(true);
        });
    }
}
