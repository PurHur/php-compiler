<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\dom\VmDom;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\JIT\VmActiveContextInitLlvm;

/** Lazy registration for ext/dom JIT instance-method proxies (#17130). */
final class DomInstanceMethodJit
{
    public static function isDomInstanceMethodProxy(string $proxyName): bool
    {
        $lc = strtolower(ltrim($proxyName, '\\'));
        if (self::shouldDeferToVmClassMethodLowering()) {
            return self::isUserScriptDomMethod($lc);
        }

        return (bool) preg_match('/^dom[a-z0-9_]*::[a-z0-9_]+$/', $lc);
    }

    /** User-script AOT: direct LLVM for createElement/loadHTML/getElementById (#17954). */
    private static function isUserScriptDomMethod(string $proxyLc): bool
    {
        if ('domdocument::createelement' === $proxyLc
            || 'domdocument::loadhtml' === $proxyLc
            || 'domdocument::getelementbyid' === $proxyLc) {
            return true;
        }

        return false;
    }

    /** User-script AOT: nested VmDomInstanceInvoke JIT aborts — use VmClassMethod lowering (#15407, #17391). */
    public static function shouldDeferToVmClassMethodLowering(): bool
    {
        $userScript = getenv('PHP_COMPILER_AOT_USER_SCRIPT');
        if ('1' === $userScript || 'true' === strtolower((string) $userScript)) {
            return true;
        }

        return false;
    }

    public static function ensureProxy(Context $context, string $proxyName): void
    {
        $lc = strtolower(ltrim($proxyName, '\\'));
        if (self::shouldDeferToVmClassMethodLowering()) {
            if ('domdocument::createelement' === $lc) {
                $context->functionProxies[$lc] = new Call\DomDocumentCreateElement();

                return;
            }
            if ('domdocument::loadhtml' === $lc) {
                $context->functionProxies[$lc] = new Call\DomDocumentLoadHTML();

                return;
            }
            if ('domdocument::getelementbyid' === $lc) {
                $context->functionProxies[$lc] = new Call\DomDocumentGetElementById();

                return;
            }
            if (!self::isUserScriptDomMethod($lc)) {
                return;
            }
        }
        if (isset($context->functionProxies[$lc])
            && !($context->functionProxies[$lc] instanceof Call\ExternalMethod)) {
            return;
        }
        if (!self::isDomInstanceMethodProxy($lc)) {
            return;
        }
        if (!preg_match('/^(dom[a-z0-9_]*)::([a-z0-9_]+)$/', $lc, $matches)) {
            return;
        }
        $context->functionProxies[$lc] = new Call\DomInstanceMethod($matches[1], $matches[2]);
    }

    /** Register domdocument::createelement without generic nested helper (#17130). */
    public static function registerKnownProxies(Context $context): void
    {
        if (self::shouldDeferToVmClassMethodLowering()) {
            VmActiveContextInitLlvm::requestThinStandaloneInit($context);
            self::ensureDomElementPropertyLayout($context);
            self::ensureProxy($context, 'domdocument::createelement');
            self::ensureProxy($context, 'domdocument::loadhtml');
            self::ensureProxy($context, 'domdocument::getelementbyid');

            return;
        }
        foreach (self::KNOWN_METHODS as $classLc => $methods) {
            foreach ($methods as $methodLc) {
                self::ensureProxy($context, $classLc.'::'.$methodLc);
            }
        }
    }

    /** @var array<string, list<string>> */
    private const KNOWN_METHODS = [
        'domdocument' => ['createelement', 'appendchild', 'loadhtml', 'getelementbyid'],
        'domnode' => ['appendchild'],
        'domelement' => ['setattribute'],
        'domtokenlist' => ['add', 'contains', 'item', 'toggle', 'remove'],
    ];

    private static function ensureDomElementPropertyLayout(Context $context): void
    {
        $object = $context->type->object;
        $classId = $object->lookup('DOMElement');
        if ($object->hasProperty($classId, 'nodeName')) {
            return;
        }
        $object->defineProperty($classId, 'nodeName', Variable::TYPE_STRING);
        $object->defineProperty($classId, 'tagName', Variable::TYPE_STRING);
        $object->defineProperty($classId, 'attributes', Variable::TYPE_VALUE);
        $object->defineProperty($classId, 'textContent', Variable::TYPE_STRING);
        $docId = $object->lookup('DOMDocument');
        if (!$object->hasProperty($docId, VmDom::PROP_ELEMENT_ID_MAP)) {
            $object->defineProperty($docId, VmDom::PROP_ELEMENT_ID_MAP, Variable::TYPE_VALUE);
        }
        if (!$object->hasProperty($docId, VmDom::PROP_REGISTRY_ID)) {
            $object->defineProperty($docId, VmDom::PROP_REGISTRY_ID, Variable::TYPE_VALUE);
        }
    }
}
