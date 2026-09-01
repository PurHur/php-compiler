<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\DomInstanceMethodJit;
use PHPCompiler\JIT\Scope;
use PHPCompiler\JIT\Variable as JITVariable;

/**
 * Route DOM instance calls away from RuntimeIndirect on closure-capture / :object temps (#31251).
 *
 * RuntimeIndirect + compile-time arg fold drops surplus operands; Zend ACE becomes SIGSEGV.
 * php-src: ext/dom/* ZEND_PARSE_PARAMETERS
 */
final class DomExcessArgcJitRoute
{
    /** @var list<string> */
    private const METHOD_LCS = [
        'createdocument',
        'hasfeature',
        'remove',
        'lookupprefix',
        'lookupnamespaceuri',
        'isdefaultnamespace',
        'issupported',
        'c14nfile',
        'schemavalidate',
        'schemavalidatesource',
        'relaxngvalidate',
        'relaxngvalidatesource',
        'load',
        'save',
        'savehtmlfile',
        'createcdatasection',
        'createdocumentfragment',
        'createentityreference',
        'createprocessinginstruction',
        'registernodeclass',
        'setattributenode',
        'removeattributenode',
        'registerphpfunctions',
    ];

    public static function tryRouteNonObjectReceiver(
        Context $context,
        string $methodLc,
        JITVariable $receiverVar,
        Scope $scope
    ): bool {
        $methodLc = strtolower($methodLc);
        if (!\in_array($methodLc, self::METHOD_LCS, true)) {
            return false;
        }
        if (!JitDomDocumentMethodKernel::shouldUse($context)) {
            return false;
        }
        foreach (self::proxyCandidates($methodLc) as $proxy) {
            DomInstanceMethodJit::ensureProxy($context, $proxy);
            if ($context->functionIsRegistered($proxy)) {
                $scope->toCall = $context->resolveFunctionProxy($proxy);
                $scope->args = [$receiverVar];

                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function proxyCandidates(string $methodLc): array
    {
        $candidates = [
            'domimplementation::'.$methodLc,
            'dom\\implementation::'.$methodLc,
            'domnode::'.$methodLc,
            'domelement::'.$methodLc,
            'domdocument::'.$methodLc,
            'dom\\document::'.$methodLc,
            'dom\\htmldocument::'.$methodLc,
            'dom\\xmldocument::'.$methodLc,
            'domxpath::'.$methodLc,
            'dom\\xpath::'.$methodLc,
            'domtext::'.$methodLc,
            'domcharacterdata::'.$methodLc,
        ];
        if ('remove' === $methodLc) {
            $candidates[] = 'domcomment::remove';
            $candidates[] = 'domcdatasection::remove';
        }

        return $candidates;
    }
}
