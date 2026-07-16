<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;

use PHPCompiler\JIT\Context;

/** JIT/AOT link for DOMXPath::evaluate() via DomXPathEvaluateJitHelper (#18526). */
final class DomXPathEvaluateRuntime
{
    public const ABI_BOOL = '__phpc_dom_xpath_evaluate_bool';

    public const ABI_DOUBLE = '__phpc_dom_xpath_evaluate_double';

    public const ABI_STRING = '__phpc_dom_xpath_evaluate_string';

    private const HELPER_PATH = '/ext/dom/DomXPathEvaluateJitHelper.php';

    private const BOOL_HELPER = 'PHPCompiler\\ext\\dom\\DomXPathEvaluateJitHelper::evaluateBoolArgv';

    private const DOUBLE_HELPER = 'PHPCompiler\\ext\\dom\\DomXPathEvaluateJitHelper::evaluateDoubleArgv';

    private const STRING_HELPER = 'PHPCompiler\\ext\\dom\\DomXPathEvaluateJitHelper::evaluateStringArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::BOOL_HELPER,
        self::DOUBLE_HELPER,
        self::STRING_HELPER,
    ];

    public static function ensureBoolLinked(Context $context): void
    {
        JitDomDocumentMethodKernel::ensureXPathEvaluateBoolBridge($context);
    }

    public static function ensureDoubleLinked(Context $context): void
    {
        JitDomDocumentMethodKernel::ensureXPathEvaluateDoubleBridge($context);
    }

    public static function ensureStringLinked(Context $context): void
    {
        JitDomDocumentMethodKernel::ensureXPathEvaluateStringBridge($context);
    }
}
