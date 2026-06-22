<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\ext\standard\JitBoolArg;
use PHPCompiler\ext\standard\JitHashFile;
use PHPCompiler\ext\standard\JitStringBuiltinArg;
use PHPCompiler\ext\standard\VmHashFile;
use PHPCompiler\ext\standard\VmStreamOpenFailure;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** hash_file() — hash file contents (ext/hash/hash.c, issue #3221). */
final class hash_file extends Internal
{
    public function __construct()
    {
        parent::__construct('hash_file');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('hash_file() requires two or three arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $algo = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'hash_file', 0, 'algo');
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'hash_file', 1, 'filename');
        $raw = false;
        if (3 === $argc) {
            $rawArg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $rawArg->type) {
                throw new \LogicException('hash_file() raw_output must be boolean in this compiler build');
            }
            $raw = $rawArg->toBool();
        }
        $result = VmHashFile::hashFile($algo, $path, $raw);
        if (false === $result) {
            VmStreamOpenFailure::warnFailedToOpen($frame, 'hash_file', $path);
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2 || \count($args) > 3) {
            throw new \LogicException('hash_file() requires two or three arguments in this compiler build');
        }
        $raw = $context->getTypeFromString('int1')->constInt(0, false);
        if (isset($args[2])) {
            $raw = JitBoolArg::lower($context, $args[2], 'hash_file() raw_output');
        }
        $algo = JitStringBuiltinArg::lower($context, $args[0], 'hash_file', 0, 'algo');
        $path = JitStringBuiltinArg::lower($context, $args[1], 'hash_file', 1, 'filename');

        return JitHashFile::hash($context, $algo, $path, $raw);
    }
}
