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
            return self::isUserScriptDirectMethod($lc) || self::isUserScriptGenericDomMethod($lc);
        }

        return (bool) preg_match('/^dom[a-z0-9_]*::[a-z0-9_]+$/', $lc);
    }

    /** User-script AOT: dedicated LLVM bridges (#17954, #18268). */
    private static function isUserScriptDirectMethod(string $proxyLc): bool
    {
        return isset(self::USER_SCRIPT_DIRECT_METHODS[$proxyLc]);
    }

    /** User-script AOT: generic VmDomInstanceInvoke bridge (#18493). */
    private static function isUserScriptGenericDomMethod(string $proxyLc): bool
    {
        return isset(self::USER_SCRIPT_GENERIC_DOM_METHODS[$proxyLc]);
    }

    /** @var array<string, true> */
    private const USER_SCRIPT_GENERIC_DOM_METHODS = [
        'domxpath::registernamespace' => true,
        'domnode::comparedocumentposition' => true,
    ];

    /** @var array<string, true> */
    private const USER_SCRIPT_DIRECT_METHODS = [
        'domdocument::createelement' => true,
        'domdocument::createelementns' => true,
        'domdocument::createcomment' => true,
        'domdocument::createattributens' => true,
        'domdocument::load' => true,
        'domdocument::loadhtml' => true,
        'domdocument::loadhtmlfile' => true,
        'domdocument::getelementbyid' => true,
        'domdocument::loadxml' => true,
        'domdocument::savexml' => true,
        'domdocument::savehtml' => true,
        'domdocument::savehtmlfile' => true,
        'domdocument::getelementsbytagname' => true,
        'domdocument::appendchild' => true,
        'domnode::appendchild' => true,
        'domdocumentfragment::appendchild' => true,
        'domnode::append' => true,
        'domnode::prepend' => true,
        'domnode::replacechildren' => true,
        'domnode::removechild' => true,
        'domelement::removechild' => true,
        'domnode::replacechild' => true,
        'domelement::replacechild' => true,
        'domdocument::createdocumentfragment' => true,
        'domdocument::importnode' => true,
        'domdocument::adoptnode' => true,
        'domelement::getattribute' => true,
        'domnode::getattribute' => true,
        'domelement::setattribute' => true,
        'domelement::getattributenode' => true,
        'domelement::getattributenodens' => true,
        'domelement::setattributenodens' => true,
        'domelement::toggleattribute' => true,
        'domnode::contains' => true,
        'domnode::getrootnode' => true,
        'domnode::isequalnode' => true,
        'domnode::c14n' => true,
        'domelement::c14n' => true,
        'domdocument::c14n' => true,
        'domxpath::query' => true,
        'domxpath::evaluate' => true,
        'domnodelist::item' => true,
    ];

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
            if ('domdocument::createelementns' === $lc) {
                $context->functionProxies[$lc] = new Call\DomDocumentCreateElementNS();

                return;
            }
            if ('domdocument::createcomment' === $lc) {
                $context->functionProxies[$lc] = new Call\DomDocumentCreateComment();

                return;
            }
            if ('domdocument::load' === $lc) {
                $context->functionProxies[$lc] = new Call\DomDocumentLoad();

                return;
            }
            if ('domdocument::loadhtml' === $lc) {
                $context->functionProxies[$lc] = new Call\DomDocumentLoadHTML();

                return;
            }
            if ('domdocument::loadhtmlfile' === $lc) {
                $context->functionProxies[$lc] = new Call\DomDocumentLoadHTMLFile();

                return;
            }
            if ('domdocument::getelementbyid' === $lc) {
                $context->functionProxies[$lc] = new Call\DomDocumentGetElementById();

                return;
            }
            if ('domdocument::importnode' === $lc) {
                $context->functionProxies[$lc] = new Call\DomDocumentImportNode();

                return;
            }
            if ('domelement::getattribute' === $lc || 'domnode::getattribute' === $lc) {
                $context->functionProxies[$lc] = new Call\DomElementGetAttribute();

                return;
            }
            if ('domelement::setattribute' === $lc) {
                $context->functionProxies[$lc] = new Call\DomElementSetAttribute();

                return;
            }
            if ('domelement::getattributenode' === $lc) {
                $context->functionProxies[$lc] = new Call\DomElementGetAttributeNode();

                return;
            }
            if ('domelement::getattributenodens' === $lc) {
                $context->functionProxies[$lc] = new Call\DomElementGetAttributeNodeNS();

                return;
            }
            if ('domelement::setattributenodens' === $lc) {
                $context->functionProxies[$lc] = new Call\DomElementSetAttributeNodeNS();

                return;
            }
            if ('domdocument::createattributens' === $lc) {
                $context->functionProxies[$lc] = new Call\DomDocumentCreateAttributeNS();

                return;
            }
            if ('domdocument::loadxml' === $lc) {
                $context->functionProxies[$lc] = new Call\DomDocumentLoadXML();

                return;
            }
            if ('domdocument::savexml' === $lc) {
                $context->functionProxies[$lc] = new Call\DomDocumentSaveXML();

                return;
            }
            if ('domdocument::savehtml' === $lc) {
                $context->functionProxies[$lc] = new Call\DomDocumentSaveHTML();

                return;
            }
            if ('domdocument::savehtmlfile' === $lc) {
                $context->functionProxies[$lc] = new Call\DomDocumentSaveHTMLFile();

                return;
            }
            if ('domdocument::getelementsbytagname' === $lc) {
                $context->functionProxies[$lc] = new Call\DomDocumentGetElementsByTagName();

                return;
            }
            if ('domdocument::appendchild' === $lc) {
                $context->functionProxies[$lc] = new Call\DomDocumentAppendChild();

                return;
            }
            if ('domelement::appendchild' === $lc) {
                $context->functionProxies[$lc] = new Call\DomNodeAppendChild();

                return;
            }
            if ('domnode::appendchild' === $lc) {
                $context->functionProxies[$lc] = new Call\DomNodeAppendChild();

                return;
            }
            if ('domdocumentfragment::appendchild' === $lc) {
                $context->functionProxies[$lc] = new Call\DomNodeAppendChild();

                return;
            }
            if ('domnode::append' === $lc) {
                $context->functionProxies[$lc] = new Call\DomNodeAppend();

                return;
            }
            if ('domnode::prepend' === $lc) {
                $context->functionProxies[$lc] = new Call\DomNodePrepend();

                return;
            }
            if ('domnode::replacechildren' === $lc) {
                $context->functionProxies[$lc] = new Call\DomNodeReplaceChildren();

                return;
            }
            if ('domelement::toggleattribute' === $lc) {
                $context->functionProxies[$lc] = new Call\DomElementToggleAttribute();

                return;
            }
            if ('domnode::contains' === $lc) {
                $context->functionProxies[$lc] = new Call\DomNodeContains();

                return;
            }
            if ('domnode::getrootnode' === $lc) {
                $context->functionProxies[$lc] = new Call\DomNodeGetRootNode();

                return;
            }
            if ('domnode::isequalnode' === $lc) {
                $context->functionProxies[$lc] = new Call\DomNodeIsEqualNode();

                return;
            }
            if ('domnode::c14n' === $lc || 'domelement::c14n' === $lc || 'domdocument::c14n' === $lc) {
                $context->functionProxies[$lc] = new Call\DomNodeC14N();

                return;
            }
            if ('domelement::removechild' === $lc || 'domnode::removechild' === $lc) {
                $context->functionProxies[$lc] = new Call\DomNodeRemoveChild();

                return;
            }
            if ('domelement::replacechild' === $lc || 'domnode::replacechild' === $lc) {
                $context->functionProxies[$lc] = new Call\DomNodeReplaceChild();

                return;
            }
            if ('domdocument::createdocumentfragment' === $lc) {
                $context->functionProxies[$lc] = new Call\DomDocumentCreateDocumentFragment();

                return;
            }
            if ('domxpath::query' === $lc) {
                $context->functionProxies[$lc] = new Call\DomXPathQuery();

                return;
            }
            if ('domxpath::evaluate' === $lc) {
                $context->functionProxies[$lc] = new Call\DomXPathEvaluate();

                return;
            }
            if ('domnodelist::item' === $lc) {
                $context->functionProxies[$lc] = new Call\DomNodeListItem();

                return;
            }
            if (!self::isUserScriptDirectMethod($lc) && !self::isUserScriptGenericDomMethod($lc)) {
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
            self::ensureProxy($context, 'domdocument::createelementns');
            self::ensureProxy($context, 'domdocument::createcomment');
            self::ensureProxy($context, 'domdocument::load');
            self::ensureProxy($context, 'domdocument::loadhtml');
            self::ensureProxy($context, 'domdocument::loadhtmlfile');
            self::ensureProxy($context, 'domdocument::getelementbyid');
            self::ensureProxy($context, 'domdocument::loadxml');
            self::ensureProxy($context, 'domdocument::savexml');
            self::ensureProxy($context, 'domdocument::savehtml');
            self::ensureProxy($context, 'domdocument::savehtmlfile');
            self::ensureProxy($context, 'domdocument::getelementsbytagname');
            self::ensureProxy($context, 'domdocument::appendchild');
            self::ensureProxy($context, 'domelement::appendchild');
            self::ensureProxy($context, 'domnode::appendchild');
            self::ensureProxy($context, 'domdocumentfragment::appendchild');
            self::ensureProxy($context, 'domnode::append');
            self::ensureProxy($context, 'domnode::prepend');
            self::ensureProxy($context, 'domnode::replacechildren');
            self::ensureProxy($context, 'domelement::toggleattribute');
            self::ensureProxy($context, 'domnode::contains');
            self::ensureProxy($context, 'domnode::getrootnode');
            self::ensureProxy($context, 'domnode::isequalnode');
            self::ensureProxy($context, 'domnode::c14n');
            self::ensureProxy($context, 'domelement::c14n');
            self::ensureProxy($context, 'domdocument::c14n');
            self::ensureProxy($context, 'domnode::removechild');
            self::ensureProxy($context, 'domelement::removechild');
            self::ensureProxy($context, 'domnode::replacechild');
            self::ensureProxy($context, 'domelement::replacechild');
            self::ensureProxy($context, 'domdocument::createdocumentfragment');
            self::ensureProxy($context, 'domxpath::query');
            self::ensureProxy($context, 'domxpath::evaluate');
            self::ensureProxy($context, 'domnodelist::item');
            self::ensureProxy($context, 'domdocument::importnode');
            self::ensureProxy($context, 'domdocument::adoptnode');
            self::ensureProxy($context, 'domelement::getattribute');
            self::ensureProxy($context, 'domnode::getattribute');
            self::ensureProxy($context, 'domelement::setattribute');
            self::ensureProxy($context, 'domelement::getattributenode');
            self::ensureProxy($context, 'domelement::getattributenodens');
            self::ensureProxy($context, 'domelement::setattributenodens');
            self::ensureProxy($context, 'domdocument::createattributens');
            self::ensureProxy($context, 'domnode::comparedocumentposition');
            self::ensureProxy($context, 'domxpath::registernamespace');

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
        'domxpath' => ['query', 'evaluate', 'registernamespace'],
        'domnodelist' => ['item'],
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
        $textId = $object->lookup('DOMText');
        if (!$object->hasProperty($textId, 'nodeName')) {
            $object->defineProperty($textId, 'nodeName', Variable::TYPE_STRING);
        }
        foreach (['DOMNode', 'DOMElement', 'DOMDocument'] as $nodeClass) {
            $nodeId = $object->lookup($nodeClass);
            foreach ([
                VmDom::PROP_FIRST_CHILD,
                VmDom::PROP_LAST_CHILD,
                VmDom::PROP_PARENT_NODE,
                VmDom::PROP_NEXT_SIBLING,
                VmDom::PROP_PREVIOUS_SIBLING,
            ] as $prop) {
                if (!$object->hasProperty($nodeId, $prop)) {
                    $object->defineProperty($nodeId, $prop, Variable::TYPE_VALUE);
                }
            }
            if (!$object->hasProperty($nodeId, VmDom::PROP_REGISTRY_ID)) {
                $object->defineProperty($nodeId, VmDom::PROP_REGISTRY_ID, Variable::TYPE_VALUE);
            }
        }
        $docId = $object->lookup('DOMDocument');
        if (!$object->hasProperty($docId, VmDom::PROP_ELEMENT_ID_MAP)) {
            $object->defineProperty($docId, VmDom::PROP_ELEMENT_ID_MAP, Variable::TYPE_VALUE);
        }
    }
}
