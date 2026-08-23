<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT compile link for OpensslPkeyGetDetailsJitHelper (#34030).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer OpensslPkeyNewEmbedBridge #34015).
 */
final class OpensslPkeyGetDetailsEmbedBridge
{
    private const HELPER_PATH = '/ext/openssl/OpensslPkeyGetDetailsJitHelper.php';

    private const FROM_PEM_HELPER = 'PHPCompiler\\ext\\openssl\\OpensslPkeyGetDetailsJitHelper::fromPem';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FROM_PEM_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function fromPemHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::FROM_PEM_HELPER, '#34030');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#34030'
        );
    }
}
