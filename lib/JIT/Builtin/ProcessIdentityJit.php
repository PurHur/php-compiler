<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\Block;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\ScriptMagic;
use PHPCompiler\OpCode;
use PHPLLVM\Value;

/**
 * JIT/AOT link for getmypid/getmyuid/getmygid/get_current_user via ProcessIdentityJitHelper PHP (#9017, #21259).
 *
 * Nested helper compile: {@see JitVmHelperLink::ensureCompiled} (HelperRuntimeCache + user-script
 * env clear — no hand-rolled NestedJit putenv). Peer: gethostname #21166 / rename #19215.
 * SSOT: {@see \PHPCompiler\ext\standard\VmProcessIdentity}, {@see \PHPCompiler\ext\standard\VmDate::getmypid}.
 * php-src: ext/standard/basic_functions.c — getmypid, getmyuid, getmygid, get_current_user
 */
final class ProcessIdentityJit
{
    private const HELPER_PATH = '/ext/standard/ProcessIdentityJitHelper.php';

    private const GETMYPID = 'PHPCompiler\\ext\\standard\\ProcessIdentityJitHelper::resolveGetmypid';

    private const GETMYUID = 'PHPCompiler\\ext\\standard\\ProcessIdentityJitHelper::resolveGetmyuid';

    private const GETMYGID = 'PHPCompiler\\ext\\standard\\ProcessIdentityJitHelper::resolveGetmygid';

    private const GET_CURRENT_USER = 'PHPCompiler\\ext\\standard\\ProcessIdentityJitHelper::resolveGetCurrentUser';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::GETMYPID,
        self::GETMYUID,
        self::GETMYGID,
        self::GET_CURRENT_USER,
    ];

    public static function getmypid(Context $context): Value
    {
        return self::callIntHelper($context, self::GETMYPID);
    }

    public static function getmyuid(Context $context): Value
    {
        return self::callIntHelper($context, self::GETMYUID);
    }

    public static function getmygid(Context $context): Value
    {
        return self::callIntHelper($context, self::GETMYGID);
    }

    public static function getCurrentUser(Context $context): Value
    {
        self::ensureCompiled($context);
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::GET_CURRENT_USER, '#21259');
        $path = '';
        $block = $context->jitEnclosingBlock;
        if ($block instanceof Block) {
            $path = ScriptMagic::stringForBlock($block, OpCode::SCRIPT_MAGIC_FILE);
        }
        $pathStr = $context->builder->load($context->constantStringFromString($path));
        $nameStr = $context->builder->call($helperFn, $pathStr);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $nameStr
        );

        return $ptr;
    }

    private static function callIntHelper(Context $context, string $logical): Value
    {
        self::ensureCompiled($context);
        $helperFn = JitVmHelperLink::lookupCompiled($context, $logical, '#21259');
        $raw = $context->builder->call($helperFn);
        $i64 = $context->getTypeFromString('int64');

        return $raw->typeOf() === $i64
            ? $raw
            : $context->builder->zExt($raw, $i64);
    }

    private static function ensureCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21259'
        );
    }
}
