<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/** JIT/AOT link for DOMNode::C14N() via DomC14NJitHelper (#19467, #22378). */
final class DomC14NRuntime
{
    public const ABI_NAME = '__phpc_dom_c14n';

    private const HELPER_PATH = '/ext/dom/DomC14NJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\dom\\DomC14NJitHelper::c14nArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureC14NBridge($context);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NAME,
            'dom_c14n_bridge',
            [$objPtr, $i64],
            $valuePtr,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#22378'
        );
    }
}
