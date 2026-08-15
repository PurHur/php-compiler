<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Thin argv bridge for `__phpc_url_rewriter_apply` (#31099 / re-#27566).
 *
 * Algorithm in {@see VmUrlRewriterHrefApply}. NestedJIT-bundled with this file
 * (peer {@see Nl2brJitHelper} / #30813). Full {@see UrlScannerEx} stays on the
 * VM execute path via {@see VmUrlRewriterFlush::applyHandler}.
 */
final class UrlRewriterApplyJitHelper
{
    public static function applyArgv(string $content, string $urlApp): string
    {
        return VmUrlRewriterHrefApply::apply($content, $urlApp);
    }
}
