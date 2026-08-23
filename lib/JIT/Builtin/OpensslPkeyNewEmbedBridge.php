<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT compile link for OpensslPkeyNewJitHelper (#34015).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer HashContextEmbedBridge #23189).
 */
final class OpensslPkeyNewEmbedBridge
{
    private const HELPER_PATH = '/ext/openssl/OpensslPkeyNewJitHelper.php';

    private const GENERATE_HELPER = 'PHPCompiler\\ext\\openssl\\OpensslPkeyNewJitHelper::generatePem';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::GENERATE_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function generateHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::GENERATE_HELPER, '#34015');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#34015'
        );
    }
}
