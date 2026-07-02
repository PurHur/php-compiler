<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmStreamOpenFailure;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/**
 * hash_update_file() — incremental hash from file contents (php-src ext/hash/hash.c; issue #14967).
 */
final class hash_update_file extends HashFunction
{
    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('hash_update_file() requires exactly two arguments in this compiler build');
        }
        $ctx = VmHashContext::requireHashContext($frame->calledArgs[0], 'hash_update_file', 1);
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'hash_update_file', 1, 'filename');

        $handle = VmFs::fopen($path, 'rb', $frame->vmContext);
        if (false === $handle) {
            VmStreamOpenFailure::warnFailedToOpen($frame, 'hash_update_file', $path);
            BuiltinExecute::writeReturn($frame, static function (Variable $ret): void {
                $ret->bool(false);
            });

            return;
        }

        $read = VmHashContext::updateFromStream($ctx, $handle, -1);
        VmFs::fclose($handle);

        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($read): void {
            $ret->bool(false !== $read);
        });
    }
}

