<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\CompilerVersion;
use PHPCompiler\JIT;
use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * dom extension module entry (php-src ext/dom/php_dom.c; issue #6140).
 *
 * PHP-in-PHP DOM factory — no runtime/*.c growth.
 */
class Module extends ModuleAbstract
{

    /**
     * php-src ext/dom builds on ext/libxml (libxml2).
     *
     * Runtime::loadCoreModules() already loads them in this order; declaring it makes the
     * constraint checkable instead of remembered (RELEASE-PLAN Phase 2.5).
     *
     * @return list<string>
     */
    /** php-src ext/dom/php_dom.h DOM_API_VERSION — libxml DOM module version (#15439). */
    private const DOM_API_VERSION = '20031129';

    public function getExtensionVersion(): string
    {
        return self::DOM_API_VERSION;
    }

    public function jitInit(JIT\Context $context): void
    {
        // DOMDocument::__construct — seed nodeType for thin AOT (#33607 / #36204).
        $context->functionProxies['domdocument::__construct'] = new JIT\Call\DomDocumentConstruct();

        // Dom\HTMLDocument/XMLDocument factory Call proxies (#27108, #27300, #35804 / #36204).
        if (CompilerVersion::supportsDomLivingStandardNamespaceJitLowering()) {
            $context->functionProxies['dom\\xmldocument::createfromstring'] = new JIT\Call\DomXmlDocumentCreateFromString();
            $context->functionProxies['dom\\htmldocument::createfromstring'] = new JIT\Call\DomHtmlDocumentCreateFromString();
            $context->functionProxies['dom\\xmldocument::createfromfile'] = new JIT\Call\DomXmlDocumentCreateFromFile();
            $context->functionProxies['dom\\htmldocument::createfromfile'] = new JIT\Call\DomHtmlDocumentCreateFromFile();
        }

        // DOMNode::DOCUMENT_POSITION_* — php-src php_dom.stub.php (#36204).
        $context->type->object->registerExternalClassSeeder('domnode', static function ($obj, int $id): void {
            if (!CompilerVersion::supportsDomNodeCompareDocumentPosition()) {
                return;
            }
            $obj->seedExternalClassConstants($id, [
                'document_position_disconnected' => DomConstants::DOCUMENT_POSITION_DISCONNECTED,
                'document_position_preceding' => DomConstants::DOCUMENT_POSITION_PRECEDING,
                'document_position_following' => DomConstants::DOCUMENT_POSITION_FOLLOWING,
                'document_position_contains' => DomConstants::DOCUMENT_POSITION_CONTAINS,
                'document_position_contained_by' => DomConstants::DOCUMENT_POSITION_CONTAINED_BY,
                'document_position_implementation_specific' => DomConstants::DOCUMENT_POSITION_IMPLEMENTATION_SPECIFIC,
            ]);
        });
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getFunctions(): array
    {
        $fns = [
            new dom_import_simplexml(),
        ];
        // PHP 8.4 Dom\ living API (php-src php_dom.stub.php; #20711).
        // Class ns_import_simplexml — not Dom_import_* (case-collides with legacy).
        if (CompilerVersion::supportsDomLivingStandardNamespace()) {
            $fns[] = new ns_import_simplexml();
        }

        return $fns;
    }
}
