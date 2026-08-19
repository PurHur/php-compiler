<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * User-script AOT for DOMNode::getNodePath() (php-src xmlGetNodePath).
 *
 * Thin standalone AOT documentElement/firstChild temps lose DOMElement userType
 * and NestedJIT DomRegistry is empty — instance-invoke aborts as
 * object::getnodepath(). Fold the compile-time loadXML walk into a string
 * (peer {@see JitDomHasChildNodes} / {@see JitDomHasAttributes}).
 *
 * php-src: ext/dom/node.c PHP_METHOD(DOMNode, getNodePath) (#32474)
 */
final class JitDomGetNodePath
{
    /** Last documentElement / firstChild / lastChild xmlGetNodePath string. */
    public static ?string $lastPath = null;

    /** Inner markup of that node — used for nested firstChild chains (#32474). */
    public static ?string $lastInner = null;

    /** Parent whose children firstChild/lastChild currently enumerate. */
    public static ?string $lastParentPath = null;

    public static ?string $lastParentInner = null;

    public static bool $lastChildFetchWasFirstChild = false;

    public static function rememberWalk(?string $path, ?string $inner): void
    {
        self::$lastPath = $path;
        self::$lastInner = $inner;
    }

    public static function annotateDocumentElement(JITVariable $result): void
    {
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml || !JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            return;
        }
        $tag = DomParseSimpleXmlJitHelper::rootTagArgv($xml);
        $path = '/'.$tag;
        $inner = DomParseSimpleXmlJitHelper::rootInnerXmlArgv($xml);
        $result->compileTimeDomNodePath = $path;
        $result->compileTimeDomInnerXml = $inner;
        $result->compileTimeDomTagName = $tag;
        $result->compileTimeDomLineNo = JitDomGetLineNo::rootLineNo(
            JitDomLoadXMLUserScript::lastCompileTimeXmlSource() ?? $xml
        );
        self::$lastParentPath = $path;
        self::$lastParentInner = $inner;
        self::$lastChildFetchWasFirstChild = false;
        self::rememberWalk($path, $inner);
    }

    public static function annotateChildFetch(JITVariable $result, string $propName): void
    {
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml || !JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            return;
        }
        $propLc = strtolower($propName);
        $nested = 'firstchild' === $propLc
            && self::$lastChildFetchWasFirstChild
            && null !== self::$lastInner;
        if ($nested) {
            $parentPath = self::$lastPath;
            $parentInner = self::$lastInner;
        } else {
            $parentPath = self::$lastParentPath;
            $parentInner = self::$lastParentInner;
            if (null === $parentInner) {
                $parentPath = '/'.DomParseSimpleXmlJitHelper::rootTagArgv($xml);
                $parentInner = DomParseSimpleXmlJitHelper::rootInnerXmlArgv($xml);
            }
        }
        $siblings = DomParseSimpleXmlJitHelper::parseSiblingNodesArgv((string) $parentInner);
        if ([] === $siblings) {
            return;
        }
        $index = 'lastchild' === $propLc ? \count($siblings) - 1 : 0;
        $segment = DomParseSimpleXmlJitHelper::nodePathSegmentArgv($siblings, $index);
        if (null === $segment || '' === $segment) {
            return;
        }
        $path = rtrim((string) $parentPath, '/').'/'.$segment;
        $inner = $siblings[$index]['inner'] ?? '';
        $result->compileTimeDomNodePath = $path;
        $result->compileTimeDomInnerXml = $inner;
        if ('element' === ($siblings[$index]['kind'] ?? '')) {
            $result->compileTimeDomTagName = $siblings[$index]['data'];
        }
        $result->compileTimeDomChildIndex = $index;
        $result->compileTimeDomLineNo = JitDomGetLineNo::childLineNo(
            JitDomLoadXMLUserScript::lastCompileTimeXmlSource() ?? $xml,
            (string) $parentInner,
            $index
        );
        self::$lastChildFetchWasFirstChild = 'firstchild' === $propLc;
        self::rememberWalk($path, $inner);
    }

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_getnodepath_cont');
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMNode::getNodePath',
            0
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        $receiver = $args[0] ?? null;
        if (null === $receiver) {
            throw new \LogicException('DOMNode::getNodePath() expects a receiver');
        }
        $class = strtolower(str_replace('/', '\\', ltrim((string) $receiver->classUserType, '\\')));
        if (self::isDocumentClass($class)) {
            return self::boxStringResult($context, '/');
        }
        if (null !== $receiver->compileTimeDomNodePath) {
            return self::boxStringResult($context, $receiver->compileTimeDomNodePath);
        }

        return self::emitLiveSlotPath($context, $receiver);
    }

    /** Bake xmlGetNodePath onto a materialized node (loadXML tree). */
    public static function storeOn(Context $context, Value $obj, string $className, string $path): void
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup($className);
        if (!$objectType->hasProperty($classId, VmDom::PROP_NODE_PATH)) {
            $objectType->defineProperty($classId, VmDom::PROP_NODE_PATH, JITVariable::TYPE_STRING);
        }
        $str = $context->builder->load($context->constantStringFromString($path));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $propVar = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $owned
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, $className, VmDom::PROP_NODE_PATH),
            $propVar,
            JITVariable::TYPE_STRING
        );
    }

    private static function emitLiveSlotPath(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $objectType = $context->type->object;
        $objMap = $context->structFieldMap['__object__'];
        $classIdVal = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        $docBb = $fn->appendBasicBlock('dom_gnp_doc');
        $elBb = $fn->appendBasicBlock('dom_gnp_el');
        $doneBb = $fn->appendBasicBlock('dom_gnp_done');
        $docId = $objectType->lookup('DOMDocument');
        $isDoc = $context->builder->icmp(
            Builder::INT_EQ,
            $classIdVal,
            $context->constantFromInteger($docId, 'int64')
        );
        $xmlDocId = $objectType->lookup('Dom\\XMLDocument');
        $isXmlDoc = $context->builder->icmp(
            Builder::INT_EQ,
            $classIdVal,
            $context->constantFromInteger($xmlDocId, 'int64')
        );
        $context->builder->branchIf($context->builder->or($isDoc, $isXmlDoc), $docBb, $elBb);

        $context->builder->positionAtEnd($docBb);
        $docPtr = self::boxStringResult($context, '/');
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($elBb);
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_NODE_PATH)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_NODE_PATH, JITVariable::TYPE_STRING);
        }
        $pathVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $obj,
            'DOMElement',
            VmDom::PROP_NODE_PATH,
            $elementClassId
        );
        $pathStr = $context->helper->loadValue($pathVar);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $pathStr
        );
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $owned
        );
        $elPtr = JitValueBox::normalizeValuePtr($context, $slot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($docPtr->typeOf());
        $phi->addIncoming($docPtr, $docBb);
        $phi->addIncoming($elPtr, $elBb);

        return $phi;
    }

    private static function loadObject(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException('DOMNode::getNodePath() receiver must be object or value box');
    }

    private static function isDocumentClass(string $classLc): bool
    {
        return str_contains($classLc, 'document') && !str_contains($classLc, 'element');
    }

    private static function boxStringResult(Context $context, string $lit): Value
    {
        $str = $context->builder->load($context->constantStringFromString($lit));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $owned
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }
}
