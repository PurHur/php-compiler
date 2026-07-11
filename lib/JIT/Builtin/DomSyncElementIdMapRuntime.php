<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/** Main-module sync after loadHTML helper TU mutates DomRegistry (#17954). */
final class DomSyncElementIdMapRuntime
{
    public const ABI_NAME = '__phpc_dom_sync_element_id_map';

    private const HELPER_PATH = '/ext/dom/DomSyncElementIdMapJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\dom\\DomSyncElementIdMapJitHelper::syncArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        if (!DomDocumentMethodUserScriptLlvm::shouldUse($context)) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        DomDocumentMethodUserScriptLlvm::ensureSyncElementIdMapBridge($context);
    }
}
