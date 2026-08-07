<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\ext\standard\JitArrayElem;
use PHPCompiler\ext\standard\JitHashFile;
use PHPCompiler\ext\standard\JitStreamPath;
use PHPCompiler\ext\standard\VmArray;
use PHPCompiler\ext\standard\VmHashFile;
use PHPCompiler\ext\standard\VmStreamOpenFailure;
use PHPCompiler\ext\standard\VmStreamPath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * hash_file() — hash file contents (ext/hash/hash.c, issue #3221).
 *
 * Stub: hash_file(string $algo, string $filename, bool $binary = false, array $options = []): string|false
 * Excess argc → ArgumentCountError; non-array $options → TypeError (#28315).
 */
final class hash_file extends Internal
{
    public function __construct()
    {
        parent::__construct('hash_file');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/hash/hash.stub.php — ArgumentCountError / options TypeError (#28315).
        $this->requireArgCountRange($frame, 'hash_file', 2, 4);
        if (null === $frame->returnVar) {
            return;
        }
        $argc = \count($frame->calledArgs);
        // Z_PARAM_STR $algo — non-strict null is E_DEPRECATED + '' then ValueError (#21572, reverts #20304).
        $algo = VmString::trimFamilyStringArgForFrame($frame, 0, 'hash_file', 0, 'algo');
        $path = VmStreamPath::coerceNonEmptyPathArgForFrame($frame, 1, 'hash_file', 'filename');
        $raw = false;
        if ($argc >= 3) {
            $rawArg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $rawArg->type) {
                throw new \LogicException('hash_file() raw_output must be boolean in this compiler build');
            }
            $raw = $rawArg->toBool();
        }
        if (4 === $argc) {
            // Z_PARAM_ARRAY $options — stub parity; unused for sha256/sha1/md5 (#28315).
            VmArray::requireArrayParam($frame->calledArgs[3], 'hash_file', 4, 'options');
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
        $argc = \count($args);
        // Catchable ArgumentCountError (AOT try/catch) — peer md5 #28313 / #28315.
        if ($argc < 2 || $argc > 4) {
            $slot = JitValueBox::alloc($context);
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                $argc < 2
                    ? \sprintf('hash_file() expects at least 2 arguments, %d given', $argc)
                    : \sprintf('hash_file() expects at most 4 arguments, %d given', $argc)
            );

            return $slot;
        }
        $raw = $context->getTypeFromString('int1')->constInt(0, false);
        if (isset($args[2])) {
            $raw = JitBoolArg::lower($context, $args[2], 'hash_file() raw_output');
        }
        if (isset($args[3])) {
            // Z_PARAM_ARRAY $options — type-checked; unused for sha256/sha1/md5 (#28315).
            JitArrayElem::requireArrayParam($context, $args[3], 'hash_file', 4, 'options');
        }
        $algo = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'hash_file', 0, 'algo')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'hash_file', 0, 'algo');
        $path = JitStreamPath::lowerNonEmptyPath($context, $args[1], 'hash_file', 1, 'filename');

        return JitHashFile::hash($context, $algo, $path, $raw);
    }
}
