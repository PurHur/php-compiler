<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT/AOT link for DOMXPath::evaluate() via DomXPathEvaluateJitHelper (#18526). */
final class DomXPathEvaluateRuntime
{
    public const ABI_BOOL = '__phpc_dom_xpath_evaluate_bool';

    public const ABI_DOUBLE = '__phpc_dom_xpath_evaluate_double';

    private const HELPER_PATH = '/ext/dom/DomXPathEvaluateJitHelper.php';

    private const BOOL_HELPER = 'PHPCompiler\\ext\\dom\\DomXPathEvaluateJitHelper::evaluateBoolArgv';

    private const DOUBLE_HELPER = 'PHPCompiler\\ext\\dom\\DomXPathEvaluateJitHelper::evaluateDoubleArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::BOOL_HELPER,
        self::DOUBLE_HELPER,
    ];

    public static function ensureBoolLinked(Context $context): void
    {
        DomDocumentMethodUserScriptLlvm::ensureXPathEvaluateBoolBridge($context);
    }

    public static function ensureDoubleLinked(Context $context): void
    {
        DomDocumentMethodUserScriptLlvm::ensureXPathEvaluateDoubleBridge($context);
    }
}
