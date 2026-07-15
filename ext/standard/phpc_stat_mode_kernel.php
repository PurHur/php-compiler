<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * @internal libc st_mode kernel for StatPathJitHelper (#19215).
 *
 * Returns st_mode on success, or -1 when the path cannot be statted.
 * useLstat=0 → stat(2), useLstat≠0 → lstat(2).
 * php-src: Zend/zend_stat.c — php_stat / php_lstat
 */
final class phpc_stat_mode_kernel extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_stat_mode_kernel');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \LogicException('phpc_stat_mode_kernel() expects exactly 2 arguments, '.$argc.' given');
        }
        $path = VmFilestatArg::coerceFilenameArg($frame->calledArgs[0], 'phpc_stat_mode_kernel', 0, 'filename', $frame);
        $useLstat = $frame->calledArgs[1]->toInt();
        $raw = 0 !== $useLstat ? @\lstat($path) : @\stat($path);
        $mode = false === $raw || !\is_array($raw) ? -1 : (int) ($raw['mode'] ?? -1);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($mode);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('phpc_stat_mode_kernel() expects exactly 2 arguments');
        }
        $path = JitStringBuiltinArg::lowerPath($context, $args[0], 'phpc_stat_mode_kernel', 0, 'filename');
        $useLstat = self::literalUseLstat($args[1]);
        $i64 = $context->getTypeFromString('int64');
        if (null !== $useLstat) {
            $modeI32 = JitStatKernel::mode($context, $path, $useLstat);

            return $context->builder->sext($modeI32, $i64);
        }

        $modeStat = JitStatKernel::mode($context, $path, false);
        $modeLstat = JitStatKernel::mode($context, $path, true);
        $flag = $args[1]->value;
        $nonzero = $context->builder->icmp(Builder::INT_NE, $flag, $i64->constInt(0, false));
        $modeI32 = $context->builder->select($nonzero, $modeLstat, $modeStat);

        return $context->builder->sext($modeI32, $i64);
    }

    private static function literalUseLstat(JITVariable $arg): ?bool
    {
        if (JITVariable::TYPE_NATIVE_LONG !== $arg->type || JITVariable::KIND_VALUE !== $arg->kind) {
            return null;
        }
        try {
            return 0 !== (int) $arg->value->getConstantValue();
        } catch (\Throwable) {
            return null;
        }
    }
}
