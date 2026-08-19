<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmStreamArg;
use PHPCompiler\ext\standard\VmStreamContext;
use PHPCompiler\ext\standard\VmStreamOpenFailure;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/**
 * hash_update_file() — incremental hash from file contents (php-src ext/hash/hash.c; issue #14967, JIT/AOT #32464).
 *
 * Reflection / named-arg params match Zend stub `context,filename,stream_context` (#24563).
 */
final class hash_update_file extends HashFunction
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'hash_update_file() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'hash_update_file() expects at most 3 arguments, %d given',
                $argc
            ));
        }
        $ctx = VmHashContext::requireHashContext($frame->calledArgs[0], 'hash_update_file', 1);
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'hash_update_file', 1, 'filename');

        // Optional $stream_context — validate resource shape like fopen()/file() (#24563).
        // Local paths ignore wrapper options today; null / default matches Zend digest.
        if (isset($frame->calledArgs[2])) {
            $contextVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $contextVar->type) {
                if (!VmStreamContext::isRepresentation($contextVar)) {
                    throw new \TypeError(\sprintf(
                        'hash_update_file(): Argument #3 ($stream_context) must be of type resource or null, %s given',
                        VmStreamArg::debugTypeName($contextVar)
                    ));
                }
            }
        }

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
