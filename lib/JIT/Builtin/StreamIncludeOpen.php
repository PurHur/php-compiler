<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/** JIT/AOT link hook for script/include allow_url_include gate (#32104). */
final class StreamIncludeOpen
{
    private const HELPER_PATH = '/ext/standard/StreamIncludeOpenJitHelper.php';

    private const HELPER_LOGICAL = 'PHPCompiler\\ext\\standard\\StreamIncludeOpenJitHelper::warnIfBlocked';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#32104'
        );
    }

    public static function helperFunction(Context $context): \PHPLLVM\Value\Function_
    {
        self::ensureLinked($context);

        return JitVmHelperLink::lookupCompiled($context, self::HELPER_LOGICAL, '#32104');
    }
}
