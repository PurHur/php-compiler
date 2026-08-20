<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/** JIT/AOT link for DOMNode::C14NFile() via DomC14NJitHelper (#32964). */
final class DomC14NFileRuntime
{
    public const ABI_NAME = '__phpc_dom_c14n_file';

    private const HELPER_PATH = '/ext/dom/DomC14NJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\dom\\DomC14NJitHelper::c14nFileArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
        'PHPCompiler\\ext\\dom\\DomC14NJitHelper::c14nArgv',
    ];

    public static function ensureLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureC14NFileBridge($context);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NAME,
            'dom_c14n_file_bridge',
            [$objPtr, $strPtr, $i64],
            $i64,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#32964'
        );
    }
}
