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

        return (bool) preg_match('/^dom(\\\\[a-z0-9_]+|[a-z0-9_]*)::[a-z0-9_]+$/', $lc);
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
        'domxpath::registerphpfunctionns' => true,
        'domnode::comparedocumentposition' => true,
    ];

    /** @var array<string, true> */
    private const USER_SCRIPT_DIRECT_METHODS = [
        'domdocument::createelement' => true,
        'domdocument::createelementns' => true,
        // Living Dom\Document createElement* — object receiver + helper (#28958).
        'dom\\document::createelement' => true,
        'dom\\document::createelementns' => true,
        'dom\\htmldocument::createelement' => true,
        'dom\\htmldocument::createelementns' => true,
        'dom\\xmldocument::createelement' => true,
        'dom\\xmldocument::createelementns' => true,
        'domdocument::createcomment' => true,
        'domdocument::createtextnode' => true,
        'domdocument::createcdatasection' => true,
        'domdocument::createprocessinginstruction' => true,
        'domdocument::createentityreference' => true,
        'domdocument::createattributens' => true,
        'domdocument::createattribute' => true,
        'domdocument::load' => true,
        'domdocument::loadhtml' => true,
        'domdocument::loadhtmlfile' => true,
        'domdocument::getelementbyid' => true,
        'domdocument::loadxml' => true,
        'domdocument::savexml' => true,
        'domdocument::savehtml' => true,
        'domdocument::savehtmlfile' => true,
        'domdocument::getelementsbytagname' => true,
        'domelement::getelementsbytagname' => true,
        'domdocument::getelementsbytagnamens' => true,
        'domdocument::appendchild' => true,
        'domnode::appendchild' => true,
        'domdocumentfragment::appendchild' => true,
        'domnode::append' => true,
        'domelement::append' => true,
        'domdocument::append' => true,
        'domdocumentfragment::append' => true,
        'domnode::prepend' => true,
        'domelement::prepend' => true,
        'domdocument::prepend' => true,
        'domdocumentfragment::prepend' => true,
        'domnode::replacechildren' => true,
        'domelement::replacechildren' => true,
        'domdocument::replacechildren' => true,
        'domdocumentfragment::replacechildren' => true,
        'domnode::removechild' => true,
        'domelement::removechild' => true,
        'domnode::replacechild' => true,
        'domelement::replacechild' => true,
        'domnode::insertbefore' => true,
        'domelement::insertbefore' => true,
        'domnode::after' => true,
        'domelement::after' => true,
        'domcharacterdata::after' => true,
        'domtext::after' => true,
        'domcomment::after' => true,
        'domnode::before' => true,
        'domelement::before' => true,
        'domcharacterdata::before' => true,
        'domtext::before' => true,
        'domcomment::before' => true,
        'domnode::replacewith' => true,
        'domelement::replacewith' => true,
        'domcharacterdata::replacewith' => true,
        'domtext::replacewith' => true,
        'domcomment::replacewith' => true,
        'domnode::remove' => true,
        'domelement::remove' => true,
        'domcharacterdata::remove' => true,
        'domtext::remove' => true,
        'domcomment::remove' => true,
        'domnode::normalize' => true,
        'domelement::normalize' => true,
        'domdocument::normalize' => true,
        'domdocumentfragment::normalize' => true,
        'domdocument::normalizedocument' => true,
        'domdocument::createdocumentfragment' => true,
        'domdocument::importnode' => true,
        'domdocument::adoptnode' => true,
        'dom\\document::importlegacynode' => true,
        'dom\\xmldocument::importlegacynode' => true,
        'dom\\htmldocument::importlegacynode' => true,
        'dom\\document::importnode' => true,
        'dom\\xmldocument::importnode' => true,
        'dom\\htmldocument::importnode' => true,
        'dom\\document::adoptnode' => true,
        'dom\\xmldocument::adoptnode' => true,
        'dom\\htmldocument::adoptnode' => true,
        'domelement::getattribute' => true,
        'domnode::getattribute' => true,
        'domelement::setattribute' => true,
        'domelement::removeattribute' => true,
        'domelement::getattributenode' => true,
        'domelement::getattributenodens' => true,
        'domelement::setattributenodens' => true,
        'domelement::setattributenode' => true,
        // setIdAttribute* — dedicated true/false ABI (NestedJIT bool unsafe; #29257, #29284).
        'domelement::setidattribute' => true,
        'domelement::setidattributens' => true,
        'domelement::setidattributenode' => true,
        // DOMAttr::isId() — VmDomInstanceInvoke bridge (#29884, re-#20129).
        'domattr::isid' => true,
        'dom\\attr::isid' => true,
        'domelement::toggleattribute' => true,
        'domtext::substringdata' => true,
        'domcomment::substringdata' => true,
        'domcdatasection::substringdata' => true,
        'domcharacterdata::substringdata' => true,
        'domtext::appenddata' => true,
        'domcomment::appenddata' => true,
        'domcdatasection::appenddata' => true,
        'domcharacterdata::appenddata' => true,
        'domtext::insertdata' => true,
        'domcomment::insertdata' => true,
        'domcdatasection::insertdata' => true,
        'domcharacterdata::insertdata' => true,
        'domtext::deletedata' => true,
        'domcomment::deletedata' => true,
        'domcdatasection::deletedata' => true,
        'domcharacterdata::deletedata' => true,
        'domtext::replacedata' => true,
        'domcomment::replacedata' => true,
        'domcdatasection::replacedata' => true,
        'domcharacterdata::replacedata' => true,
        'domnode::clonenode' => true,
        'domelement::clonenode' => true,
        'domnode::haschildnodes' => true,
        'domelement::haschildnodes' => true,
        'domdocument::haschildnodes' => true,
        'domdocumentfragment::haschildnodes' => true,
        'dom\\node::haschildnodes' => true,
        'dom\\element::haschildnodes' => true,
        'dom\\htmlelement::haschildnodes' => true,
        'dom\\document::haschildnodes' => true,
        'domnode::hasattributes' => true,
        'domelement::hasattributes' => true,
        'domdocument::hasattributes' => true,
        'dom\\node::hasattributes' => true,
        'dom\\element::hasattributes' => true,
        'dom\\htmlelement::hasattributes' => true,
        'domnode::getnodepath' => true,
        'domelement::getnodepath' => true,
        'domdocument::getnodepath' => true,
        'dom\\node::getnodepath' => true,
        'dom\\element::getnodepath' => true,
        'dom\\htmlelement::getnodepath' => true,
        'dom\\document::getnodepath' => true,
        'domnode::getlineno' => true,
        'domelement::getlineno' => true,
        'domdocument::getlineno' => true,
        'dom\\node::getlineno' => true,
        'dom\\element::getlineno' => true,
        'dom\\htmlelement::getlineno' => true,
        'dom\\document::getlineno' => true,
        'domnode::issupported' => true,
        'domelement::issupported' => true,
        'domdocument::issupported' => true,
        'dom\\node::issupported' => true,
        'dom\\element::issupported' => true,
        'domnode::lookupprefix' => true,
        'domelement::lookupprefix' => true,
        'domdocument::lookupprefix' => true,
        'dom\\node::lookupprefix' => true,
        'dom\\element::lookupprefix' => true,
        'domnode::lookupnamespaceuri' => true,
        'domelement::lookupnamespaceuri' => true,
        'domdocument::lookupnamespaceuri' => true,
        'dom\\node::lookupnamespaceuri' => true,
        'dom\\element::lookupnamespaceuri' => true,
        'domnode::isdefaultnamespace' => true,
        'domelement::isdefaultnamespace' => true,
        'domdocument::isdefaultnamespace' => true,
        'dom\\node::isdefaultnamespace' => true,
        'dom\\element::isdefaultnamespace' => true,
        'domtext::clonenode' => true,
        'domtext::splittext' => true,
        'domcdatasection::splittext' => true,
        'domtext::iswhitespaceinelementcontent' => true,
        'domcdatasection::iswhitespaceinelementcontent' => true,
        'domtext::iselementcontentwhitespace' => true,
        'domcdatasection::iselementcontentwhitespace' => true,
        'domcomment::clonenode' => true,
        'domdocumentfragment::clonenode' => true,
        'domattr::clonenode' => true,
        'domnode::contains' => true,
        'domnode::comparedocumentposition' => true,
        'domnode::getrootnode' => true,
        'domnode::isequalnode' => true,
        'domnode::issamenode' => true,
        'domnode::c14n' => true,
        'domelement::c14n' => true,
        'domdocument::c14n' => true,
        'domxpath::query' => true,
        'domxpath::evaluate' => true,
        'domxpath::registernamespace' => true,
        'domxpath::registerphpfunctions' => true,
        'domnodelist::item' => true,
        'domnodelist::getiterator' => true,
        'domnamednodemap::getnameditem' => true,
        'domnamednodemap::getnameditemns' => true,
        'domnamednodemap::getiterator' => true,
        'dom\\nodelist::getiterator' => true,
        'dom\\htmlcollection::getiterator' => true,
        'dom\\namednodemap::getnameditem' => true,
        'dom\\namednodemap::getnameditemns' => true,
        'dom\\namednodemap::getiterator' => true,
        'dom\\dtdnamednodemap::getnameditem' => true,
        'dom\\dtdnamednodemap::getnameditemns' => true,
        'dom\\dtdnamednodemap::getiterator' => true,
        'domtokenlist::getiterator' => true,
        'dom\\tokenlist::getiterator' => true,
        'dom\\htmldocument::queryselector' => true,
        'dom\\htmldocument::queryselectorall' => true,
        'dom\\document::queryselector' => true,
        'dom\\document::queryselectorall' => true,
        'dom\\xmldocument::queryselector' => true,
        'dom\\xmldocument::queryselectorall' => true,
        'dom\\htmldocument::getelementbyid' => true,
        'dom\\htmldocument::savehtml' => true,
        'domimplementation::createdocumenttype' => true,
        'domimplementation::hasfeature' => true,
        'dom\\implementation::hasfeature' => true,
        // Dom\Attr::rename / Dom\Element::rename — php-src @implementation-alias (#21083, #27108).
        'dom\\attr::rename' => true,
        'dom\\element::rename' => true,
        'dom\\htmlelement::rename' => true,
        // Living attribute APIs via LLVM user-script path (#27108).
        'domelement::hasattribute' => true,
        'domelement::hasattributens' => true,
        'domelement::setattributens' => true,
        'domelement::removeattributens' => true,
        'domelement::getattributens' => true,
        'dom\\element::hasattribute' => true,
        'dom\\element::hasattributens' => true,
        'dom\\element::setattributens' => true,
        'dom\\element::removeattributens' => true,
        'dom\\element::getattribute' => true,
        'dom\\element::getattributens' => true,
        'dom\\element::getattributenode' => true,
        'dom\\element::getattributenodens' => true,
        'dom\\htmlelement::hasattribute' => true,
        'dom\\htmlelement::setattributens' => true,
        'dom\\htmlelement::removeattributens' => true,
        'dom\\htmlelement::getattribute' => true,
        'dom\\htmlelement::getattributens' => true,
        'dom\\htmlelement::getattributenode' => true,
        'dom\\document::createattribute' => true,
        'dom\\xmldocument::createattribute' => true,
        'dom\\htmldocument::createattribute' => true,
    ];

    /** User-script AOT: nested VmDomInstanceInvoke JIT aborts — use VmClassMethod lowering (#15407, #17391). */
    public static function shouldDeferToVmClassMethodLowering(): bool
    {
        return UserScriptAotEnv::isActive();
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
            if ('dom\\document::createelement' === $lc
                || 'dom\\xmldocument::createelement' === $lc
            ) {
                $context->functionProxies[$lc] = new Call\DomLivingDocumentCreateElement(
                    'Dom\\Element',
                    false
                );

                return;
            }
            if ('dom\\htmldocument::createelement' === $lc) {
                $context->functionProxies[$lc] = new Call\DomLivingDocumentCreateElement(
                    'Dom\\HTMLElement',
                    true
                );

                return;
            }
            if ('dom\\document::createelementns' === $lc
                || 'dom\\xmldocument::createelementns' === $lc
            ) {
                $context->functionProxies[$lc] = new Call\DomLivingDocumentCreateElementNS(false);

                return;
            }
            if ('dom\\htmldocument::createelementns' === $lc) {
                $context->functionProxies[$lc] = new Call\DomLivingDocumentCreateElementNS(true);

                return;
            }
            if ('domdocument::createcomment' === $lc) {
                $context->functionProxies[$lc] = new Call\DomDocumentCreateComment();

                return;
            }
            if ('domdocument::createtextnode' === $lc) {
                $context->functionProxies[$lc] = new Call\DomDocumentCreateTextNode();

                return;
            }
            if ('domdocument::createcdatasection' === $lc) {
                $context->functionProxies[$lc] = new Call\DomDocumentCreateCDATASection();

                return;
            }
            if ('domdocument::createprocessinginstruction' === $lc) {
                // User-script AOT (#32331) — peer createComment/createTextNode (#32315).
                $context->functionProxies[$lc] = new Call\DomDocumentCreateProcessingInstruction();

                return;
            }
            if ('domdocument::createentityreference' === $lc) {
                // User-script AOT (#32343) — peer createComment/createDocumentFragment (#32315/#32334).
                $context->functionProxies[$lc] = new Call\DomDocumentCreateEntityReference();

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
            if ('domdocument::adoptnode' === $lc) {
                $context->functionProxies[$lc] = new Call\DomDocumentAdoptNode();

                return;
            }
            if ('dom\\document::importlegacynode' === $lc
                || 'dom\\xmldocument::importlegacynode' === $lc
                || 'dom\\htmldocument::importlegacynode' === $lc
                || 'dom\\document::importnode' === $lc
                || 'dom\\xmldocument::importnode' === $lc
                || 'dom\\htmldocument::importnode' === $lc
                || 'dom\\document::adoptnode' === $lc
                || 'dom\\xmldocument::adoptnode' === $lc
                || 'dom\\htmldocument::adoptnode' === $lc
            ) {
                if (!preg_match('/^(dom\\\\[a-z0-9_]+)::([a-z0-9_]+)$/', $lc, $livingImportMatches)) {
                    return;
                }
                if ('adoptnode' === $livingImportMatches[2]) {
                    $context->functionProxies[$lc] = new Call\DomDocumentAdoptNode();

                    return;
                }
                $context->functionProxies[$lc] = new Call\DomInstanceMethod(
                    $livingImportMatches[1],
                    $livingImportMatches[2]
                );

                return;
            }
            if ('domelement::getattributenode' === $lc
                || 'dom\\element::getattributenode' === $lc
                || 'dom\\htmlelement::getattributenode' === $lc
            ) {
                $context->functionProxies[$lc] = new Call\DomElementGetAttributeNode();

                return;
            }
            if ('domelement::getattribute' === $lc
                || 'domnode::getattribute' === $lc
                || 'dom\\element::getattribute' === $lc
                || 'dom\\htmlelement::getattribute' === $lc
            ) {
                $context->functionProxies[$lc] = new Call\DomElementGetAttribute();

                return;
            }
            if ('domelement::getattributens' === $lc
                || 'dom\\element::getattributens' === $lc
                || 'dom\\htmlelement::getattributens' === $lc
            ) {
                $context->functionProxies[$lc] = new Call\DomElementGetAttributeNS();

                return;
            }
            if ('domelement::hasattribute' === $lc
                || 'dom\\element::hasattribute' === $lc
                || 'dom\\htmlelement::hasattribute' === $lc
            ) {
                $context->functionProxies[$lc] = new Call\DomElementHasAttribute();

                return;
            }
            if ('domelement::hasattributens' === $lc) {
                $context->functionProxies[$lc] = new Call\DomElementHasAttributeNS();

                return;
            }
            if ('domelement::setattributens' === $lc
                || 'dom\\element::setattributens' === $lc
                || 'dom\\htmlelement::setattributens' === $lc
            ) {
                $context->functionProxies[$lc] = new Call\DomElementSetAttributeNS();

                return;
            }
            if ('domelement::removeattributens' === $lc
                || 'dom\\element::removeattributens' === $lc
                || 'dom\\htmlelement::removeattributens' === $lc
            ) {
                $context->functionProxies[$lc] = new Call\DomElementRemoveAttributeNS();

                return;
            }
            if ('dom\\attr::rename' === $lc) {
                $context->functionProxies[$lc] = new Call\DomAttrRename();

                return;
            }
            if ('domdocument::createattribute' === $lc
                || 'dom\\xmldocument::createattribute' === $lc
                || 'dom\\document::createattribute' === $lc
                || 'dom\\htmldocument::createattribute' === $lc
            ) {
                $context->functionProxies[$lc] = new Call\DomDocumentCreateAttribute();

                return;
            }
            if ('domelement::getattributenode' === $lc) {
                $context->functionProxies[$lc] = new Call\DomElementGetAttributeNode();

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
            if ('domelement::removeattribute' === $lc) {
                $context->functionProxies[$lc] = new Call\DomElementRemoveAttribute();

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
            if ('domdocument::createattribute' === $lc) {
                $context->functionProxies[$lc] = new Call\DomDocumentCreateAttribute();

                return;
            }
            if ('domelement::setattributenode' === $lc) {
                $context->functionProxies[$lc] = new Call\DomElementSetAttributeNode();

                return;
            }
            if ('domelement::setidattribute' === $lc) {
                $context->functionProxies[$lc] = new Call\DomElementSetIdAttribute();

                return;
            }
            if ('domelement::setidattributens' === $lc) {
                $context->functionProxies[$lc] = new Call\DomElementSetIdAttributeNS();

                return;
            }
            if ('domelement::setidattributenode' === $lc) {
                $context->functionProxies[$lc] = new Call\DomElementSetIdAttributeNode();

                return;
            }
            if ('domattr::isid' === $lc || 'dom\\attr::isid' === $lc) {
                $context->functionProxies[$lc] = new Call\DomAttrIsId();

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
            if ('dom\\htmldocument::savehtml' === $lc) {
                $context->functionProxies[$lc] = new Call\DomHtmlDocumentSaveHtml();

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
            if ('domelement::getelementsbytagname' === $lc) {
                $context->functionProxies[$lc] = new Call\DomElementGetElementsByTagName();

                return;
            }
            if ('domdocument::getelementsbytagnamens' === $lc) {
                $context->functionProxies[$lc] = new Call\DomDocumentGetElementsByTagNameNS();

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
            if ('domnode::append' === $lc
                || 'domelement::append' === $lc
                || 'domdocument::append' === $lc
                || 'domdocumentfragment::append' === $lc
            ) {
                $context->functionProxies[$lc] = new Call\DomNodeAppend();

                return;
            }
            if ('domnode::prepend' === $lc
                || 'domelement::prepend' === $lc
                || 'domdocument::prepend' === $lc
                || 'domdocumentfragment::prepend' === $lc
            ) {
                $context->functionProxies[$lc] = new Call\DomNodePrepend();

                return;
            }
            if ('domnode::replacechildren' === $lc
                || 'domelement::replacechildren' === $lc
                || 'domdocument::replacechildren' === $lc
                || 'domdocumentfragment::replacechildren' === $lc
            ) {
                $context->functionProxies[$lc] = new Call\DomNodeReplaceChildren();

                return;
            }
            if ('domelement::toggleattribute' === $lc) {
                $context->functionProxies[$lc] = new Call\DomElementToggleAttribute();

                return;
            }
            if ('domtext::substringdata' === $lc
                || 'domcomment::substringdata' === $lc
                || 'domcdatasection::substringdata' === $lc
                || 'domcharacterdata::substringdata' === $lc
            ) {
                $context->functionProxies[$lc] = new Call\DomCharacterDataSubstringData();

                return;
            }
            if ('domtext::appenddata' === $lc
                || 'domcomment::appenddata' === $lc
                || 'domcdatasection::appenddata' === $lc
                || 'domcharacterdata::appenddata' === $lc
            ) {
                $context->functionProxies[$lc] = new Call\DomCharacterDataAppendData();

                return;
            }
            if ('domtext::insertdata' === $lc
                || 'domcomment::insertdata' === $lc
                || 'domcdatasection::insertdata' === $lc
                || 'domcharacterdata::insertdata' === $lc
            ) {
                $context->functionProxies[$lc] = new Call\DomCharacterDataInsertData();

                return;
            }
            if ('domtext::deletedata' === $lc
                || 'domcomment::deletedata' === $lc
                || 'domcdatasection::deletedata' === $lc
                || 'domcharacterdata::deletedata' === $lc
            ) {
                $context->functionProxies[$lc] = new Call\DomCharacterDataDeleteData();

                return;
            }
            if ('domtext::replacedata' === $lc
                || 'domcomment::replacedata' === $lc
                || 'domcdatasection::replacedata' === $lc
                || 'domcharacterdata::replacedata' === $lc
            ) {
                $context->functionProxies[$lc] = new Call\DomCharacterDataReplaceData();

                return;
            }
            if ('domnode::clonenode' === $lc
                || 'domelement::clonenode' === $lc
                || 'domtext::clonenode' === $lc
                || 'domcomment::clonenode' === $lc
                || 'domdocumentfragment::clonenode' === $lc
                || 'domattr::clonenode' === $lc
            ) {
                $context->functionProxies[$lc] = new Call\DomNodeCloneNode();

                return;
            }
            if ('domnode::haschildnodes' === $lc
                || 'domelement::haschildnodes' === $lc
                || 'domdocument::haschildnodes' === $lc
                || 'domdocumentfragment::haschildnodes' === $lc
                || 'dom\\node::haschildnodes' === $lc
                || 'dom\\element::haschildnodes' === $lc
                || 'dom\\htmlelement::haschildnodes' === $lc
                || 'dom\\document::haschildnodes' === $lc
            ) {
                $context->functionProxies[$lc] = new Call\DomNodeHasChildNodes();

                return;
            }
            if ('domnode::hasattributes' === $lc
                || 'domelement::hasattributes' === $lc
                || 'domdocument::hasattributes' === $lc
                || 'dom\\node::hasattributes' === $lc
                || 'dom\\element::hasattributes' === $lc
                || 'dom\\htmlelement::hasattributes' === $lc
            ) {
                $context->functionProxies[$lc] = new Call\DomNodeHasAttributes();

                return;
            }
            if ('domnode::getnodepath' === $lc
                || 'domelement::getnodepath' === $lc
                || 'domdocument::getnodepath' === $lc
                || 'dom\\node::getnodepath' === $lc
                || 'dom\\element::getnodepath' === $lc
                || 'dom\\htmlelement::getnodepath' === $lc
                || 'dom\\document::getnodepath' === $lc
            ) {
                $context->functionProxies[$lc] = new Call\DomNodeGetNodePath();

                return;
            }
            if ('domnode::getlineno' === $lc
                || 'domelement::getlineno' === $lc
                || 'domdocument::getlineno' === $lc
                || 'dom\\node::getlineno' === $lc
                || 'dom\\element::getlineno' === $lc
                || 'dom\\htmlelement::getlineno' === $lc
                || 'dom\\document::getlineno' === $lc
            ) {
                $context->functionProxies[$lc] = new Call\DomNodeGetLineNo();

                return;
            }
            if ('domnode::issupported' === $lc
                || 'domelement::issupported' === $lc
                || 'domdocument::issupported' === $lc
                || 'dom\\node::issupported' === $lc
                || 'dom\\element::issupported' === $lc
            ) {
                $context->functionProxies[$lc] = new Call\DomNodeIsSupported();

                return;
            }
            if ('domnode::lookupprefix' === $lc
                || 'domelement::lookupprefix' === $lc
                || 'domdocument::lookupprefix' === $lc
                || 'dom\\node::lookupprefix' === $lc
                || 'dom\\element::lookupprefix' === $lc
            ) {
                $context->functionProxies[$lc] = new Call\DomNodeLookupPrefix();

                return;
            }
            if ('domnode::lookupnamespaceuri' === $lc
                || 'domelement::lookupnamespaceuri' === $lc
                || 'domdocument::lookupnamespaceuri' === $lc
                || 'dom\\node::lookupnamespaceuri' === $lc
                || 'dom\\element::lookupnamespaceuri' === $lc
            ) {
                $context->functionProxies[$lc] = new Call\DomNodeLookupNamespaceURI();

                return;
            }
            if ('domnode::isdefaultnamespace' === $lc
                || 'domelement::isdefaultnamespace' === $lc
                || 'domdocument::isdefaultnamespace' === $lc
                || 'dom\\node::isdefaultnamespace' === $lc
                || 'dom\\element::isdefaultnamespace' === $lc
            ) {
                $context->functionProxies[$lc] = new Call\DomNodeIsDefaultNamespace();

                return;
            }
            if ('domtext::splittext' === $lc || 'domcdatasection::splittext' === $lc) {
                $context->functionProxies[$lc] = new Call\DomTextSplitText();

                return;
            }
            if ('domtext::iswhitespaceinelementcontent' === $lc
                || 'domcdatasection::iswhitespaceinelementcontent' === $lc
                || 'domtext::iselementcontentwhitespace' === $lc
                || 'domcdatasection::iselementcontentwhitespace' === $lc
            ) {
                $context->functionProxies[$lc] = new Call\DomTextIsWhitespaceInElementContent();

                return;
            }
            if ('domnode::contains' === $lc) {
                $context->functionProxies[$lc] = new Call\DomNodeContains();

                return;
            }
            if ('domnode::comparedocumentposition' === $lc) {
                $context->functionProxies[$lc] = new Call\DomNodeCompareDocumentPosition();

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
            if ('domnode::issamenode' === $lc) {
                $context->functionProxies[$lc] = new Call\DomNodeIsSameNode();

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
            if ('domelement::insertbefore' === $lc || 'domnode::insertbefore' === $lc) {
                $context->functionProxies[$lc] = new Call\DomNodeInsertBefore();

                return;
            }
            if ('domnode::after' === $lc
                || 'domelement::after' === $lc
                || 'domcharacterdata::after' === $lc
                || 'domtext::after' === $lc
                || 'domcomment::after' === $lc
            ) {
                $context->functionProxies[$lc] = new Call\DomNodeAfter();

                return;
            }
            if ('domnode::before' === $lc
                || 'domelement::before' === $lc
                || 'domcharacterdata::before' === $lc
                || 'domtext::before' === $lc
                || 'domcomment::before' === $lc
            ) {
                $context->functionProxies[$lc] = new Call\DomNodeBefore();

                return;
            }
            if ('domnode::replacewith' === $lc
                || 'domelement::replacewith' === $lc
                || 'domcharacterdata::replacewith' === $lc
                || 'domtext::replacewith' === $lc
                || 'domcomment::replacewith' === $lc
            ) {
                $context->functionProxies[$lc] = new Call\DomNodeReplaceWith();

                return;
            }
            if ('domnode::remove' === $lc
                || 'domelement::remove' === $lc
                || 'domcharacterdata::remove' === $lc
                || 'domtext::remove' === $lc
                || 'domcomment::remove' === $lc
            ) {
                $context->functionProxies[$lc] = new Call\DomNodeChildRemove();

                return;
            }
            if ('domnode::normalize' === $lc
                || 'domelement::normalize' === $lc
                || 'domdocument::normalize' === $lc
                || 'domdocumentfragment::normalize' === $lc
            ) {
                $context->functionProxies[$lc] = new Call\DomNodeNormalize();

                return;
            }
            if ('domdocument::normalizedocument' === $lc) {
                $context->functionProxies[$lc] = new Call\DomDocumentNormalizeDocument();

                return;
            }
            if ('domdocument::createdocumentfragment' === $lc) {
                $context->functionProxies[$lc] = new Call\DomDocumentCreateDocumentFragment();

                return;
            }
            if ('domimplementation::createdocumenttype' === $lc) {
                $context->functionProxies[$lc] = new Call\DomImplementationCreateDocumentType();

                return;
            }
            if ('domimplementation::hasfeature' === $lc
                || 'dom\\implementation::hasfeature' === $lc
            ) {
                $context->functionProxies[$lc] = new Call\DomImplementationHasFeature();

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
            if ('domxpath::registernamespace' === $lc) {
                $context->functionProxies[$lc] = new Call\DomXPathRegisterNamespace();

                return;
            }
            if ('domxpath::registerphpfunctions' === $lc) {
                $context->functionProxies[$lc] = new Call\DomXPathRegisterPhpFunctions();

                return;
            }
            if ('domnodelist::item' === $lc) {
                $context->functionProxies[$lc] = new Call\DomNodeListItem();

                return;
            }
            if ('domnodelist::getiterator' === $lc
                || 'domnamednodemap::getnameditem' === $lc
                || 'domnamednodemap::getnameditemns' === $lc
                || 'domnamednodemap::getiterator' === $lc
                || 'dom\\nodelist::getiterator' === $lc
                || 'dom\\htmlcollection::getiterator' === $lc
                || 'dom\\namednodemap::getnameditem' === $lc
                || 'dom\\namednodemap::getnameditemns' === $lc
                || 'dom\\namednodemap::getiterator' === $lc
                || 'dom\\dtdnamednodemap::getnameditem' === $lc
                || 'dom\\dtdnamednodemap::getnameditemns' === $lc
                || 'dom\\dtdnamednodemap::getiterator' === $lc
                || 'domtokenlist::getiterator' === $lc
                || 'dom\\tokenlist::getiterator' === $lc
            ) {
                if (!preg_match('/^(dom(?:\\\\[a-z0-9_]+|[a-z0-9_]*))::([a-z0-9_]+)$/', $lc, $iterMatches)) {
                    return;
                }
                $context->functionProxies[$lc] = new Call\DomInstanceMethod($iterMatches[1], $iterMatches[2]);

                return;
            }
            if ('dom\\htmldocument::queryselector' === $lc
                || 'dom\\htmldocument::queryselectorall' === $lc
                || 'dom\\document::queryselector' === $lc
                || 'dom\\document::queryselectorall' === $lc
                || 'dom\\xmldocument::queryselector' === $lc
                || 'dom\\xmldocument::queryselectorall' === $lc
                || 'dom\\htmldocument::getelementbyid' === $lc
                || 'dom\\element::rename' === $lc
                || 'dom\\htmlelement::rename' === $lc
                || 'dom\\element::hasattributens' === $lc
                || 'dom\\element::getattributenodens' === $lc
                || 'dom\\htmlelement::getattributenodens' === $lc
            ) {
                if (!preg_match('/^(dom\\\\[a-z0-9_]+)::([a-z0-9_]+)$/', $lc, $livingMatches)) {
                    return;
                }
                $context->functionProxies[$lc] = new Call\DomInstanceMethod($livingMatches[1], $livingMatches[2]);

                return;
            }
            if ('dom\\document::importlegacynode' === $lc
                || 'dom\\xmldocument::importlegacynode' === $lc
                || 'dom\\htmldocument::importlegacynode' === $lc
                || 'dom\\document::importnode' === $lc
                || 'dom\\xmldocument::importnode' === $lc
                || 'dom\\htmldocument::importnode' === $lc
                || 'dom\\document::adoptnode' === $lc
                || 'dom\\xmldocument::adoptnode' === $lc
                || 'dom\\htmldocument::adoptnode' === $lc
            ) {
                if (!preg_match('/^(dom\\\\[a-z0-9_]+)::([a-z0-9_]+)$/', $lc, $livingImportMatches)) {
                    return;
                }
                if ('adoptnode' === $livingImportMatches[2]) {
                    $context->functionProxies[$lc] = new Call\DomDocumentAdoptNode();

                    return;
                }
                $context->functionProxies[$lc] = new Call\DomInstanceMethod(
                    $livingImportMatches[1],
                    $livingImportMatches[2]
                );

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
        if (!preg_match('/^(dom(?:\\\\[a-z0-9_]+|[a-z0-9_]*))::([a-z0-9_]+)$/', $lc, $matches)) {
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
            self::ensureProxy($context, 'dom\\document::createelement');
            self::ensureProxy($context, 'dom\\document::createelementns');
            self::ensureProxy($context, 'dom\\htmldocument::createelement');
            self::ensureProxy($context, 'dom\\htmldocument::createelementns');
            self::ensureProxy($context, 'dom\\xmldocument::createelement');
            self::ensureProxy($context, 'dom\\xmldocument::createelementns');
            self::ensureProxy($context, 'domdocument::createcomment');
            self::ensureProxy($context, 'domdocument::createtextnode');
            self::ensureProxy($context, 'domdocument::createcdatasection');
            self::ensureProxy($context, 'domdocument::createprocessinginstruction');
            self::ensureProxy($context, 'domdocument::createentityreference');
            self::ensureProxy($context, 'domdocument::load');
            self::ensureProxy($context, 'domdocument::loadhtml');
            self::ensureProxy($context, 'domdocument::loadhtmlfile');
            self::ensureProxy($context, 'domdocument::getelementbyid');
            self::ensureProxy($context, 'domdocument::loadxml');
            self::ensureProxy($context, 'domdocument::savexml');
            self::ensureProxy($context, 'domdocument::savehtml');
            self::ensureProxy($context, 'domdocument::savehtmlfile');
            self::ensureProxy($context, 'domdocument::getelementsbytagname');
            self::ensureProxy($context, 'domelement::getelementsbytagname');
            self::ensureProxy($context, 'domdocument::getelementsbytagnamens');
            self::ensureProxy($context, 'domdocument::appendchild');
            self::ensureProxy($context, 'domelement::appendchild');
            self::ensureProxy($context, 'domnode::appendchild');
            self::ensureProxy($context, 'domdocumentfragment::appendchild');
            self::ensureProxy($context, 'domnode::append');
            self::ensureProxy($context, 'domelement::append');
            self::ensureProxy($context, 'domdocument::append');
            self::ensureProxy($context, 'domdocumentfragment::append');
            self::ensureProxy($context, 'domnode::prepend');
            self::ensureProxy($context, 'domelement::prepend');
            self::ensureProxy($context, 'domdocument::prepend');
            self::ensureProxy($context, 'domdocumentfragment::prepend');
            self::ensureProxy($context, 'domnode::replacechildren');
            self::ensureProxy($context, 'domelement::replacechildren');
            self::ensureProxy($context, 'domdocument::replacechildren');
            self::ensureProxy($context, 'domdocumentfragment::replacechildren');
            self::ensureProxy($context, 'domelement::toggleattribute');
            self::ensureProxy($context, 'domtext::substringdata');
            self::ensureProxy($context, 'domcomment::substringdata');
            self::ensureProxy($context, 'domcdatasection::substringdata');
            self::ensureProxy($context, 'domcharacterdata::substringdata');
            self::ensureProxy($context, 'domtext::appenddata');
            self::ensureProxy($context, 'domcomment::appenddata');
            self::ensureProxy($context, 'domcdatasection::appenddata');
            self::ensureProxy($context, 'domcharacterdata::appenddata');
            self::ensureProxy($context, 'domtext::insertdata');
            self::ensureProxy($context, 'domcomment::insertdata');
            self::ensureProxy($context, 'domcdatasection::insertdata');
            self::ensureProxy($context, 'domcharacterdata::insertdata');
            self::ensureProxy($context, 'domtext::deletedata');
            self::ensureProxy($context, 'domcomment::deletedata');
            self::ensureProxy($context, 'domcdatasection::deletedata');
            self::ensureProxy($context, 'domcharacterdata::deletedata');
            self::ensureProxy($context, 'domtext::replacedata');
            self::ensureProxy($context, 'domcomment::replacedata');
            self::ensureProxy($context, 'domcdatasection::replacedata');
            self::ensureProxy($context, 'domcharacterdata::replacedata');
            self::ensureProxy($context, 'domnode::clonenode');
            self::ensureProxy($context, 'domelement::clonenode');
            self::ensureProxy($context, 'domnode::haschildnodes');
            self::ensureProxy($context, 'domelement::haschildnodes');
            self::ensureProxy($context, 'domdocument::haschildnodes');
            self::ensureProxy($context, 'domdocumentfragment::haschildnodes');
            self::ensureProxy($context, 'dom\\node::haschildnodes');
            self::ensureProxy($context, 'dom\\element::haschildnodes');
            self::ensureProxy($context, 'dom\\htmlelement::haschildnodes');
            self::ensureProxy($context, 'dom\\document::haschildnodes');
            self::ensureProxy($context, 'domnode::hasattributes');
            self::ensureProxy($context, 'domelement::hasattributes');
            self::ensureProxy($context, 'domdocument::hasattributes');
            self::ensureProxy($context, 'dom\\node::hasattributes');
            self::ensureProxy($context, 'dom\\element::hasattributes');
            self::ensureProxy($context, 'dom\\htmlelement::hasattributes');
            self::ensureProxy($context, 'domnode::getnodepath');
            self::ensureProxy($context, 'domelement::getnodepath');
            self::ensureProxy($context, 'domdocument::getnodepath');
            self::ensureProxy($context, 'dom\\node::getnodepath');
            self::ensureProxy($context, 'dom\\element::getnodepath');
            self::ensureProxy($context, 'dom\\htmlelement::getnodepath');
            self::ensureProxy($context, 'dom\\document::getnodepath');
            self::ensureProxy($context, 'domnode::getlineno');
            self::ensureProxy($context, 'domelement::getlineno');
            self::ensureProxy($context, 'domdocument::getlineno');
            self::ensureProxy($context, 'dom\\node::getlineno');
            self::ensureProxy($context, 'dom\\element::getlineno');
            self::ensureProxy($context, 'dom\\htmlelement::getlineno');
            self::ensureProxy($context, 'dom\\document::getlineno');
            self::ensureProxy($context, 'domnode::issupported');
            self::ensureProxy($context, 'domelement::issupported');
            self::ensureProxy($context, 'domdocument::issupported');
            self::ensureProxy($context, 'dom\\node::issupported');
            self::ensureProxy($context, 'dom\\element::issupported');
            self::ensureProxy($context, 'domnode::lookupprefix');
            self::ensureProxy($context, 'domelement::lookupprefix');
            self::ensureProxy($context, 'domdocument::lookupprefix');
            self::ensureProxy($context, 'dom\\node::lookupprefix');
            self::ensureProxy($context, 'dom\\element::lookupprefix');
            self::ensureProxy($context, 'domnode::lookupnamespaceuri');
            self::ensureProxy($context, 'domelement::lookupnamespaceuri');
            self::ensureProxy($context, 'domdocument::lookupnamespaceuri');
            self::ensureProxy($context, 'dom\\node::lookupnamespaceuri');
            self::ensureProxy($context, 'dom\\element::lookupnamespaceuri');
            self::ensureProxy($context, 'domnode::isdefaultnamespace');
            self::ensureProxy($context, 'domelement::isdefaultnamespace');
            self::ensureProxy($context, 'domdocument::isdefaultnamespace');
            self::ensureProxy($context, 'dom\\node::isdefaultnamespace');
            self::ensureProxy($context, 'dom\\element::isdefaultnamespace');
            self::ensureProxy($context, 'domtext::clonenode');
            self::ensureProxy($context, 'domtext::splittext');
            self::ensureProxy($context, 'domcdatasection::splittext');
            self::ensureProxy($context, 'domtext::iswhitespaceinelementcontent');
            self::ensureProxy($context, 'domcdatasection::iswhitespaceinelementcontent');
            self::ensureProxy($context, 'domtext::iselementcontentwhitespace');
            self::ensureProxy($context, 'domcdatasection::iselementcontentwhitespace');
            self::ensureProxy($context, 'domcomment::clonenode');
            self::ensureProxy($context, 'domdocumentfragment::clonenode');
            self::ensureProxy($context, 'domattr::clonenode');
            self::ensureProxy($context, 'domnode::contains');
            self::ensureProxy($context, 'domnode::comparedocumentposition');
            self::ensureProxy($context, 'domnode::getrootnode');
            self::ensureProxy($context, 'domnode::isequalnode');
            self::ensureProxy($context, 'domnode::issamenode');
            self::ensureProxy($context, 'domnode::c14n');
            self::ensureProxy($context, 'domelement::c14n');
            self::ensureProxy($context, 'domdocument::c14n');
            self::ensureProxy($context, 'domnode::removechild');
            self::ensureProxy($context, 'domelement::removechild');
            self::ensureProxy($context, 'domnode::replacechild');
            self::ensureProxy($context, 'domelement::replacechild');
            self::ensureProxy($context, 'domnode::insertbefore');
            self::ensureProxy($context, 'domelement::insertbefore');
            self::ensureProxy($context, 'domnode::after');
            self::ensureProxy($context, 'domelement::after');
            self::ensureProxy($context, 'domcharacterdata::after');
            self::ensureProxy($context, 'domtext::after');
            self::ensureProxy($context, 'domcomment::after');
            self::ensureProxy($context, 'domnode::before');
            self::ensureProxy($context, 'domelement::before');
            self::ensureProxy($context, 'domcharacterdata::before');
            self::ensureProxy($context, 'domtext::before');
            self::ensureProxy($context, 'domcomment::before');
            self::ensureProxy($context, 'domnode::replacewith');
            self::ensureProxy($context, 'domelement::replacewith');
            self::ensureProxy($context, 'domcharacterdata::replacewith');
            self::ensureProxy($context, 'domtext::replacewith');
            self::ensureProxy($context, 'domcomment::replacewith');
            self::ensureProxy($context, 'domnode::remove');
            self::ensureProxy($context, 'domelement::remove');
            self::ensureProxy($context, 'domcharacterdata::remove');
            self::ensureProxy($context, 'domtext::remove');
            self::ensureProxy($context, 'domcomment::remove');
            self::ensureProxy($context, 'domnode::normalize');
            self::ensureProxy($context, 'domelement::normalize');
            self::ensureProxy($context, 'domdocument::normalize');
            self::ensureProxy($context, 'domdocumentfragment::normalize');
            self::ensureProxy($context, 'domdocument::normalizedocument');
            self::ensureProxy($context, 'domdocument::createdocumentfragment');
            self::ensureProxy($context, 'domxpath::query');
            self::ensureProxy($context, 'domxpath::evaluate');
            self::ensureProxy($context, 'domnodelist::item');
            self::ensureProxy($context, 'domnodelist::getiterator');
            self::ensureProxy($context, 'domnamednodemap::getnameditem');
            self::ensureProxy($context, 'domnamednodemap::getnameditemns');
            self::ensureProxy($context, 'domnamednodemap::getiterator');
            self::ensureProxy($context, 'dom\\nodelist::getiterator');
            self::ensureProxy($context, 'dom\\htmlcollection::getiterator');
            self::ensureProxy($context, 'dom\\namednodemap::getnameditem');
            self::ensureProxy($context, 'dom\\namednodemap::getnameditemns');
            self::ensureProxy($context, 'dom\\namednodemap::getiterator');
            self::ensureProxy($context, 'dom\\dtdnamednodemap::getnameditem');
            self::ensureProxy($context, 'dom\\dtdnamednodemap::getnameditemns');
            self::ensureProxy($context, 'dom\\dtdnamednodemap::getiterator');
            self::ensureProxy($context, 'domtokenlist::getiterator');
            self::ensureProxy($context, 'dom\\tokenlist::getiterator');
            self::ensureProxy($context, 'domdocument::importnode');
            self::ensureProxy($context, 'domdocument::adoptnode');
            self::ensureProxy($context, 'dom\\document::importlegacynode');
            self::ensureProxy($context, 'dom\\xmldocument::importlegacynode');
            self::ensureProxy($context, 'dom\\htmldocument::importlegacynode');
            self::ensureProxy($context, 'dom\\document::importnode');
            self::ensureProxy($context, 'dom\\xmldocument::importnode');
            self::ensureProxy($context, 'dom\\htmldocument::importnode');
            self::ensureProxy($context, 'dom\\document::adoptnode');
            self::ensureProxy($context, 'dom\\xmldocument::adoptnode');
            self::ensureProxy($context, 'dom\\htmldocument::adoptnode');
            self::ensureProxy($context, 'domelement::getattribute');
            self::ensureProxy($context, 'domnode::getattribute');
            self::ensureProxy($context, 'domelement::hasattribute');
            self::ensureProxy($context, 'domelement::hasattributens');
            self::ensureProxy($context, 'domelement::getattributens');
            self::ensureProxy($context, 'domelement::setattribute');
            self::ensureProxy($context, 'domelement::setattributens');
            self::ensureProxy($context, 'domelement::removeattribute');
            self::ensureProxy($context, 'domelement::removeattributens');
            self::ensureProxy($context, 'domelement::getattributenode');
            self::ensureProxy($context, 'domelement::getattributenodens');
            self::ensureProxy($context, 'domelement::setattributenodens');
            self::ensureProxy($context, 'domelement::setattributenode');
            self::ensureProxy($context, 'domelement::setidattribute');
            self::ensureProxy($context, 'domelement::setidattributens');
            self::ensureProxy($context, 'domelement::setidattributenode');
            self::ensureProxy($context, 'domattr::isid');
            self::ensureProxy($context, 'dom\\attr::isid');
            self::ensureProxy($context, 'domdocument::createattributens');
            self::ensureProxy($context, 'domdocument::createattribute');
            self::ensureProxy($context, 'domnode::comparedocumentposition');
            self::ensureProxy($context, 'domxpath::registernamespace');
            self::ensureProxy($context, 'domxpath::registerphpfunctions');
            self::ensureProxy($context, 'domxpath::registerphpfunctionns');
            self::ensureProxy($context, 'domimplementation::createdocumenttype');
            self::ensureProxy($context, 'domimplementation::hasfeature');
            self::ensureProxy($context, 'dom\\implementation::hasfeature');
            self::ensureProxy($context, 'dom\\htmldocument::queryselector');
            self::ensureProxy($context, 'dom\\htmldocument::queryselectorall');
            self::ensureProxy($context, 'dom\\document::queryselector');
            self::ensureProxy($context, 'dom\\document::queryselectorall');
            self::ensureProxy($context, 'dom\\xmldocument::queryselector');
            self::ensureProxy($context, 'dom\\xmldocument::queryselectorall');
            self::ensureProxy($context, 'dom\\htmldocument::getelementbyid');
            self::ensureProxy($context, 'dom\\htmldocument::savehtml');
            self::ensureProxy($context, 'dom\\attr::rename');
            self::ensureProxy($context, 'dom\\element::rename');
            self::ensureProxy($context, 'dom\\htmlelement::rename');
            self::ensureProxy($context, 'dom\\element::hasattribute');
            self::ensureProxy($context, 'dom\\element::hasattributens');
            self::ensureProxy($context, 'dom\\element::setattributens');
            self::ensureProxy($context, 'dom\\element::removeattributens');
            self::ensureProxy($context, 'dom\\element::getattribute');
            self::ensureProxy($context, 'dom\\element::getattributens');
            self::ensureProxy($context, 'dom\\element::getattributenode');
            self::ensureProxy($context, 'dom\\element::getattributenodens');
            self::ensureProxy($context, 'dom\\htmlelement::hasattribute');
            self::ensureProxy($context, 'dom\\htmlelement::setattributens');
            self::ensureProxy($context, 'dom\\htmlelement::removeattributens');
            self::ensureProxy($context, 'dom\\htmlelement::getattribute');
            self::ensureProxy($context, 'dom\\htmlelement::getattributens');
            self::ensureProxy($context, 'dom\\htmlelement::getattributenode');
            self::ensureProxy($context, 'dom\\document::createattribute');
            self::ensureProxy($context, 'dom\\xmldocument::createattribute');
            self::ensureProxy($context, 'dom\\htmldocument::createattribute');

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
        'domdocument' => ['createelement', 'appendchild', 'loadhtml', 'getelementbyid', 'getnodepath', 'getlineno'],
        'domnode' => ['appendchild', 'clonenode', 'haschildnodes', 'hasattributes', 'getnodepath', 'issupported', 'lookupprefix', 'lookupnamespaceuri', 'isdefaultnamespace', 'getlineno'],
        'domimplementation' => ['createdocumenttype', 'hasfeature'],
        'domtext' => ['substringdata', 'splittext', 'appenddata', 'insertdata', 'deletedata', 'replacedata', 'iswhitespaceinelementcontent', 'iselementcontentwhitespace'],
        'domcomment' => ['substringdata', 'appenddata', 'insertdata', 'deletedata', 'replacedata'],
        'domcdatasection' => ['substringdata', 'splittext', 'appenddata', 'insertdata', 'deletedata', 'replacedata', 'iswhitespaceinelementcontent', 'iselementcontentwhitespace'],
        'domcharacterdata' => ['substringdata', 'appenddata', 'insertdata', 'deletedata', 'replacedata'],
        'domelement' => ['setattribute', 'setattributens', 'removeattribute', 'removeattributens', 'hasattributens', 'setidattribute', 'setidattributens', 'setidattributenode', 'getelementsbytagname', 'getnodepath', 'getlineno'],
        'domattr' => ['isid'],
        'domxpath' => ['query', 'evaluate', 'registernamespace', 'registerphpfunctions', 'registerphpfunctionns'],
        'domnodelist' => ['item', 'getiterator'],
        'domnamednodemap' => ['getnameditem', 'getnameditemns', 'getiterator'],
        'domtokenlist' => ['add', 'contains', 'item', 'toggle', 'remove', 'getiterator'],
        'dom\\tokenlist' => ['add', 'contains', 'item', 'toggle', 'remove', 'getiterator'],
        'dom\\nodelist' => ['getiterator'],
        'dom\\htmlcollection' => ['getiterator'],
        'dom\\namednodemap' => ['getnameditem', 'getnameditemns', 'getiterator'],
        'dom\\dtdnamednodemap' => ['getnameditem', 'getnameditemns', 'getiterator'],
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
