<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for SocketAtmarkJitHelper (#9215, #24831).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StringQuotPrint #24620).
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_atmark)
 */
final class SocketAtmarkRuntime
{
    private const HELPER_PATH = '/ext/sockets/SocketAtmarkJitHelper.php';

    private const ATMARK_HELPER = 'PHPCompiler\\ext\\sockets\\SocketAtmarkJitHelper::atmarkForHandle';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ATMARK_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureJitHelperCompiled($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }

    public static function helperFunction(Context $context): LlvmFunction
    {
        self::ensureLinked($context);

        return JitVmHelperLink::lookupCompiled($context, self::ATMARK_HELPER, '#24831');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#24831'
        );
    }
}
