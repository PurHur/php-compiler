<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\ext\standard\JitHashFile;
use PHPCompiler\ext\standard\JitStreamPath;
use PHPCompiler\ext\standard\VmHashFile;
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
 * hash_hmac_file() — HMAC over file contents (ext/hash/hash.c, issue #3221).
 *
 * Excess argc → ArgumentCountError (#28315).
 * Z_PARAM_STR $algo/$key — caller strict_types → TypeError on null (#29890; peer hash_file #21572).
 */
final class hash_hmac_file extends Internal
{
    public function __construct()
    {
        parent::__construct('hash_hmac_file');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/hash/hash.stub.php — ArgumentCountError (#28315).
        $this->requireArgCountRange($frame, 'hash_hmac_file', 3, 4);
        if (null === $frame->returnVar) {
            return;
        }
        $argc = \count($frame->calledArgs);
        // Z_PARAM_STR $algo — non-strict null is E_DEPRECATED + '' then ValueError (#29890, peer #21572).
        $algo = VmString::trimFamilyStringArgForFrame($frame, 0, 'hash_hmac_file', 0, 'algo');
        $path = VmStreamPath::coerceNonEmptyPathArgForFrame($frame, 1, 'hash_hmac_file', 'filename');
        // Z_PARAM_STR $key — same soft-null / strict_types rules as hash_hmac (#21557).
        $key = VmString::trimFamilyStringArgForFrame($frame, 2, 'hash_hmac_file', 2, 'key');
        $raw = false;
        if (4 === $argc) {
            $rawArg = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $rawArg->type) {
                throw new \LogicException('hash_hmac_file() raw_output must be boolean in this compiler build');
            }
            $raw = $rawArg->toBool();
        }
        $result = VmHashFile::hashHmacFile($algo, $path, $key, $raw);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        // Catchable ArgumentCountError (AOT try/catch) — peer hash_file #28315.
        if ($argc < 3 || $argc > 4) {
            $slot = JitValueBox::alloc($context);
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                $argc < 3
                    ? \sprintf('hash_hmac_file() expects at least 3 arguments, %d given', $argc)
                    : \sprintf('hash_hmac_file() expects at most 4 arguments, %d given', $argc)
            );

            return $slot;
        }
        $raw = $context->getTypeFromString('int1')->constInt(0, false);
        if (isset($args[3])) {
            $raw = JitBoolArg::lower($context, $args[3], 'hash_hmac_file() raw_output');
        }
        $algo = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'hash_hmac_file', 0, 'algo')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'hash_hmac_file', 0, 'algo');
        $path = JitStreamPath::lowerNonEmptyPath($context, $args[1], 'hash_hmac_file', 1, 'filename');
        $key = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[2], 'hash_hmac_file', 2, 'key')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[2], 'hash_hmac_file', 2, 'key');

        return JitHashFile::hashHmac($context, $algo, $path, $key, $raw);
    }
}
