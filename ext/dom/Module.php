<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\CompilerVersion;
use PHPCompiler\JIT;
use PHPCompiler\JIT\Variable;
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

        // DOMNode layout + DOCUMENT_POSITION_* — php-src php_dom.stub.php (#34904 / #36204).
        $context->type->object->registerExternalClassSeeder('domnode', static function ($obj, int $id): void {
            // Stub for property_exists() + inheritance — computed read via JitDomNodeBaseUri (#34904).
            $obj->defineProperty($id, VmDom::PROP_BASE_URI, Variable::TYPE_VALUE);
            $obj->markPropertyWriteReject($id, VmDom::PROP_BASE_URI);
            $obj->propagateInstancePropertyToSubclasses($id, VmDom::PROP_BASE_URI);
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

        // DOMElement allocate layout — ParentNode element-nav slots before allocate (#35007 / #36204).
        $context->type->object->registerExternalClassSeeder('domelement', static function ($obj, int $id): void {
            $obj->defineProperty($id, 'nodeName', Variable::TYPE_STRING);
            $obj->defineProperty($id, 'tagName', Variable::TYPE_STRING);
            $obj->defineProperty($id, 'localName', Variable::TYPE_STRING);
            $obj->defineProperty($id, 'attributes', Variable::TYPE_VALUE);
            $obj->defineProperty($id, 'nodeType', Variable::TYPE_NATIVE_LONG);
            $obj->defineProperty($id, 'name', Variable::TYPE_STRING);
            $obj->defineProperty($id, 'publicId', Variable::TYPE_STRING);
            $obj->defineProperty($id, 'systemId', Variable::TYPE_STRING);
            $obj->defineProperty($id, VmDom::PROP_FIRST_ELEMENT_CHILD, Variable::TYPE_VALUE);
            $obj->defineProperty($id, VmDom::PROP_LAST_ELEMENT_CHILD, Variable::TYPE_VALUE);
            $obj->defineProperty($id, VmDom::PROP_CHILD_ELEMENT_COUNT, Variable::TYPE_NATIVE_LONG);
            $obj->defineProperty($id, VmDom::PROP_NEXT_ELEMENT_SIBLING, Variable::TYPE_VALUE);
            $obj->defineProperty($id, VmDom::PROP_PREVIOUS_ELEMENT_SIBLING, Variable::TYPE_VALUE);
        });

        // DOMDocument allocate layout — must be complete before loadXML/appendChild (#32736 / #36204).
        $context->type->object->registerExternalClassSeeder('domdocument', static function ($obj, int $id): void {
            $obj->defineProperty($id, 'documentElement', Variable::TYPE_OBJECT);
            $obj->defineProperty($id, 'firstChild', Variable::TYPE_VALUE);
            $obj->defineProperty($id, 'lastChild', Variable::TYPE_VALUE);
            $obj->defineProperty($id, 'childNodes', Variable::TYPE_VALUE);
            $obj->defineProperty($id, 'nodeType', Variable::TYPE_NATIVE_LONG);
            $obj->defineProperty($id, VmDom::PROP_ELEMENT_ID_MAP, Variable::TYPE_VALUE);
            $obj->defineProperty($id, VmDom::PROP_DOCTYPE, Variable::TYPE_VALUE);
            $obj->defineProperty($id, VmDom::PROP_IMPLEMENTATION, Variable::TYPE_VALUE);
            $obj->defineProperty($id, VmDom::PROP_DOCUMENT_URI, Variable::TYPE_VALUE);
            $obj->defineProperty($id, VmDom::PROP_ENCODING, Variable::TYPE_VALUE);
            $obj->defineProperty($id, VmDom::PROP_XML_ENCODING, Variable::TYPE_VALUE);
            $obj->defineProperty($id, VmDom::PROP_ACTUAL_ENCODING, Variable::TYPE_VALUE);
            $obj->markPropertyWriteReject($id, VmDom::PROP_XML_ENCODING);
            $obj->markPropertyWriteReject($id, VmDom::PROP_ACTUAL_ENCODING);
            $obj->defineProperty($id, VmDom::PROP_XML_VERSION, Variable::TYPE_STRING);
            $obj->defineProperty($id, VmDom::PROP_VERSION, Variable::TYPE_STRING);
            $obj->defineProperty($id, VmDom::PROP_XML_STANDALONE, Variable::TYPE_VALUE);
            $obj->defineProperty($id, VmDom::PROP_STANDALONE, Variable::TYPE_VALUE);
            $obj->defineProperty($id, VmDom::PROP_STRICT_ERROR_CHECKING, Variable::TYPE_VALUE);
            $obj->defineProperty($id, VmDom::PROP_FORMAT_OUTPUT, Variable::TYPE_VALUE);
            $obj->defineProperty($id, VmDom::PROP_VALIDATE_ON_PARSE, Variable::TYPE_VALUE);
            $obj->defineProperty($id, VmDom::PROP_RESOLVE_EXTERNALS, Variable::TYPE_VALUE);
            $obj->defineProperty($id, VmDom::PROP_PRESERVE_WHITE_SPACE, Variable::TYPE_VALUE);
            $obj->defineProperty($id, VmDom::PROP_RECOVER, Variable::TYPE_VALUE);
            $obj->defineProperty($id, VmDom::PROP_SUBSTITUTE_ENTITIES, Variable::TYPE_VALUE);
            $obj->defineProperty($id, VmDom::PROP_BASE_URI, Variable::TYPE_VALUE);
            $obj->markPropertyWriteReject($id, VmDom::PROP_BASE_URI);
            $obj->defineProperty($id, VmDom::PROP_FIRST_ELEMENT_CHILD, Variable::TYPE_VALUE);
            $obj->defineProperty($id, VmDom::PROP_LAST_ELEMENT_CHILD, Variable::TYPE_VALUE);
            $obj->defineProperty($id, VmDom::PROP_CHILD_ELEMENT_COUNT, Variable::TYPE_NATIVE_LONG);
            $obj->defineProperty($id, VmDom::PROP_NODE_NAME, Variable::TYPE_STRING);
            $obj->defineProperty($id, VmDom::PROP_PREFIX, Variable::TYPE_STRING);
            $obj->defineProperty($id, VmDom::PROP_NAMESPACE_URI, Variable::TYPE_VALUE);
            $obj->defineProperty($id, VmDom::PROP_LOCAL_NAME, Variable::TYPE_VALUE);
            $obj->defineProperty($id, VmDom::PROP_ATTRIBUTES, Variable::TYPE_VALUE);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach ([
                'adoptnode',
                'importnode',
                'loadxml',
                'loadhtml',
                'appendchild',
                'createelement',
                'savexml',
                'getelementbyid',
            ] as $method) {
                $obj->defineMethodVisibility($id, $method, $pub);
            }
            $obj->markHasConstructor($id);
        });

        // Dom instanceof stand-ins + live property fetch (#33607 / #36204).
        $context->type->object->registerInstanceOfHook(
            static function ($ctx, $expr, string $className) {
                return JitDomStandinGetClass::tryEmitInstanceOf($ctx, $expr, $className);
            }
        );
        $context->type->object->registerPropertyFetchHook(
            static function (
                $object,
                $obj,
                string $class,
                string $name,
                int $classId,
                bool $forWrite,
                $receiverVar
            ) {
                return self::tryInstancePropertyFetch(
                    $object,
                    $obj,
                    $class,
                    $name,
                    $classId,
                    $forWrite,
                    $receiverVar
                );
            }
        );
        $context->type->object->registerRuntimePropertyFetchPreferrer(
            static function (array $candidates, string $name) {
                return self::preferRuntimePropertyFetchCandidate($candidates, $name);
            }
        );
    }

    /**
     * Dom live instance-property fetch previously hard-wired in ObjectInstancePropertyLlvm (#36204).
     *
     * @return \PHPCompiler\JIT\Variable|null
     */
    private static function tryInstancePropertyFetch(
        $object,
        $obj,
        string $class,
        string $name,
        int $classId,
        bool $forWrite,
        $receiverVar
    ) {
        $classLc = strtolower(str_replace('/', '\\', ltrim($class, '\\')));
        if (JitDomNodeChildProperty::isDomNodeChildProperty($classLc, strtolower($name))) {
            return JitDomNodeChildProperty::fetch(
                $object,
                $obj,
                $name,
                $classLc,
                $receiverVar
            );
        }
        if (JitDomParentNodeProperty::isDomParentNodeProperty($classLc, strtolower($name))) {
            return JitDomParentNodeProperty::fetch($object, $obj);
        }
        if (JitDomNodeIsConnected::isDomNodeIsConnected($classLc, strtolower($name))) {
            return JitDomNodeIsConnected::fetch($object, $obj);
        }
        if (JitDomElementNavigationProperty::isElementNavigationProperty($classLc, strtolower($name))) {
            return JitDomElementNavigationProperty::fetch($object, $obj, $name, $class, $receiverVar);
        }
        $propLc = strtolower($name);
        if (JitDomElementTextContent::isDomAttrValueProperty($classLc, $propLc)) {
            return JitDomElementTextContent::fetchAttrValue($object, $obj, $name, $receiverVar);
        }
        // `$o->value` read on living Dom\Attr orphan resolves to SensitiveParameterValue::$value
        // (TYPE_VALUE box) when both classes declare `value` — writes already redirect (#21083).
        if ('value' === $propLc
            && 'sensitiveparametervalue' === $classLc
            && null !== JitDomLoadXMLUserScript::lastDocumentClass()
            && str_starts_with(
                (string) JitDomLoadXMLUserScript::lastDocumentClass(),
                'Dom\\'
            )
        ) {
            return JitDomElementTextContent::fetchAttrValue($object, $obj, $name, $receiverVar);
        }
        if (JitDomElementTextContent::isDomElementTextContent($classLc, $propLc)) {
            return JitDomElementTextContent::fetchNamed($object, $obj, $name, $receiverVar);
        }
        if ('length' === strtolower($name)) {
            $recv = $receiverVar;
            if (null !== $recv) {
                $ownerOp = $recv->objectPropertyReceiverOp;
                if (null !== $ownerOp) {
                    $ctx = $object->jitContext();
                    if ($ctx->hasVariableOpInScopes($ownerOp)) {
                        $owner = $ctx->getVariableFromOpInScopes($ownerOp);
                        if (null !== ($owner->compileTimeDomAttrLocalName ?? null)) {
                            return JitDomNodeListLength::fetch($object, $obj, $recv);
                        }
                    }
                }
                if ('DOMNodeList' === ($recv->classUserType ?? '')
                    || null !== ($recv->compileTimeDomNodeListLength ?? null)
                ) {
                    return JitDomNodeListLength::fetch($object, $obj, $recv);
                }
            }
        }
        if (JitDomNodeListLength::isDomNodeListLength($classLc, strtolower($name))) {
            return JitDomNodeListLength::fetch($object, $obj, $receiverVar);
        }
        if (JitDomNamedNodeMap::isLength($classLc, strtolower($name))) {
            return JitDomNamedNodeMap::fetchLength($object, $obj);
        }
        if (JitDomNamedNodeMap::isAttributesProperty($classLc, strtolower($name))) {
            return JitDomNamedNodeMap::fetchAttributes($object, $obj, $class);
        }
        if (JitDomDocumentElement::isDomDocumentElement($classLc, strtolower($name))) {
            return JitDomDocumentElement::fetch($object, $obj, $receiverVar, $class);
        }
        if (JitDomDocumentDoctype::isDomDocumentDoctype($classLc, strtolower($name))) {
            return JitDomDocumentDoctype::fetch($object, $obj, $class);
        }
        if (JitDomNodeBaseUri::isDomNodeBaseUriProperty($classLc, strtolower($name))) {
            return JitDomNodeBaseUri::fetch($object, $obj, $class);
        }
        if (JitDomDocumentMetaProps::isDomDocumentMetaProp($classLc, strtolower($name))) {
            return JitDomDocumentMetaProps::fetch($object, $obj, $class, $name);
        }
        // childNodes must use the DOMNode slot LiveSlots/loadXML write — fetching via
        // DOMElement defineProperty'd a second index past the allocation (#327xx).
        if (JitDomChildNodesProperty::isDomChildNodesProperty($classLc, strtolower($name))) {
            return JitDomChildNodesProperty::fetch($object, $obj, $receiverVar);
        }

        return null;
    }

    /**
     * Prefer Dom\Attr when living Dom\* document shares `value`/`nodeValue` (#27108 / #36204).
     *
     * @param array<int, string> $candidates
     * @return array{0: int, 1: string}|null
     */
    private static function preferRuntimePropertyFetchCandidate(array $candidates, string $name): ?array
    {
        $propLc = strtolower($name);
        if (!\in_array($propLc, ['value', 'nodevalue'], true)
            || null === JitDomLoadXMLUserScript::lastDocumentClass()
            || !str_starts_with(
                (string) JitDomLoadXMLUserScript::lastDocumentClass(),
                'Dom\\'
            )
        ) {
            return null;
        }
        foreach ($candidates as $id => $className) {
            $classLc = strtolower(str_replace('/', '\\', ltrim($className, '\\')));
            if ('dom\\attr' === $classLc || 'domattr' === $classLc) {
                return [(int) $id, $className];
            }
        }

        return null;
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
