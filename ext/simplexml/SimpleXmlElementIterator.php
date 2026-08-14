<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/**
 * SimpleXMLElement Iterator / RecursiveIterator
 * (php-src ext/simplexml/simplexml.c + simplexml.stub.php; #19089, #21887).
 */
final class SimpleXmlElementIterator
{
    public static function registerMethods(\PHPCompiler\VM\ClassEntry $entry, int $pub): void
    {
        foreach ([
            'rewind' => SimpleXmlElementRewind::class,
            'valid' => SimpleXmlElementValid::class,
            'current' => SimpleXmlElementCurrent::class,
            'key' => SimpleXmlElementKey::class,
            'next' => SimpleXmlElementNext::class,
            'haschildren' => SimpleXmlElementHasChildren::class,
            'getchildren' => SimpleXmlElementGetChildren::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->methodNames['haschildren'] = 'hasChildren';
        $entry->methodNames['getchildren'] = 'getChildren';
    }

    public static function registerInterfaces(\PHPCompiler\VM\ClassEntry $entry, Context $ctx): void
    {
        // RecursiveIterator extends Iterator (php-src register_class_SimpleXMLElement).
        foreach (['Iterator', 'RecursiveIterator', 'Traversable'] as $iface) {
            if (isset($ctx->classes[strtolower($iface)])
                && !\in_array($iface, $entry->interfaces, true)) {
                $entry->interfaces[] = $iface;
            }
        }
    }

    /** @return list<SimpleXmlNodeState> */
    public static function iterableChildren(ObjectEntry $entry): array
    {
        return VmSimpleXml::directElementChildren($entry);
    }

    public static function wrapChild(Context $ctx, ObjectEntry $entry, int $index): ObjectEntry
    {
        $children = self::iterableChildren($entry);
        if (!isset($children[$index])) {
            throw new \LogicException('SimpleXMLElement child index out of range');
        }
        // attributes() foreach yields live attr handles (php-src sxe.c; #22654).
        if (SimpleXmlRegistry::isAttributesView($entry)) {
            return VmSimpleXml::wrapAttributeNode($ctx, $entry, $children[$index]->name);
        }
        // Preserve receiver class (SimpleXMLIterator subclass; php-src uses sxe->zo.ce).
        $class = $entry->class;

        return VmSimpleXml::wrapIteratorNode(
            $ctx,
            $class,
            $children[$index],
            SimpleXmlRegistry::documentKey($entry)
        );
    }

    /**
     * Current iterator child has element children?
     * (php-src PHP_METHOD(SimpleXMLElement, hasChildren); #21887).
     */
    public static function hasChildren(ObjectEntry $entry): bool
    {
        // php-src: UNDEF iter.data or SXE_ITER_ATTRLIST → false.
        if (SimpleXmlRegistry::isAttributesView($entry)
            || !SimpleXmlIteratorStorage::isInitialized($entry)) {
            return false;
        }
        $index = SimpleXmlIteratorStorage::index($entry);
        $children = self::iterableChildren($entry);
        if ($index < 0 || $index >= \count($children)) {
            return false;
        }

        return [] !== $children[$index]->children;
    }

    /**
     * Current iterator element as recursive child iterator, or null.
     * (php-src PHP_METHOD(SimpleXMLElement, getChildren); #21887).
     */
    public static function getChildren(Context $ctx, ObjectEntry $entry): ?ObjectEntry
    {
        if (SimpleXmlRegistry::isAttributesView($entry)
            || !SimpleXmlIteratorStorage::isInitialized($entry)) {
            return null;
        }
        $index = SimpleXmlIteratorStorage::index($entry);
        $children = self::iterableChildren($entry);
        if ($index < 0 || $index >= \count($children)) {
            return null;
        }

        return self::wrapChild($ctx, $entry, $index);
    }
}

final class SimpleXmlElementRewind extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        $entry = VmSimpleXml::requireElement(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLElement::rewind()'
        );
        SimpleXmlIteratorStorage::rewind($entry);
    }
}

final class SimpleXmlElementValid extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        $entry = VmSimpleXml::requireElement(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLElement::valid()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $index = SimpleXmlIteratorStorage::index($entry);
        $frame->returnVar->bool(
            $index >= 0 && $index < \count(SimpleXmlElementIterator::iterableChildren($entry))
        );
    }
}

final class SimpleXmlElementCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('SimpleXMLElement::current() requires VM context');
        }
        $entry = VmSimpleXml::requireElement(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLElement::current()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $index = SimpleXmlIteratorStorage::index($entry);
        $children = SimpleXmlElementIterator::iterableChildren($entry);
        if ($index < 0 || $index >= \count($children)) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->object(SimpleXmlElementIterator::wrapChild(
            $frame->vmContext,
            $entry,
            $index
        ));
    }
}

final class SimpleXmlElementKey extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        $entry = VmSimpleXml::requireElement(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLElement::key()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $children = SimpleXmlElementIterator::iterableChildren($entry);
        $index = SimpleXmlIteratorStorage::index($entry);
        if ($index < 0 || $index >= \count($children)) {
            $frame->returnVar->null();

            return;
        }
        // php-src sxe.c iterator key: local name, not prefixed QName (#20136).
        $frame->returnVar->string(VmSimpleXml::localNameFromQualified($children[$index]->name));
    }
}

final class SimpleXmlElementNext extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        $entry = VmSimpleXml::requireElement(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLElement::next()'
        );
        SimpleXmlIteratorStorage::setIndex($entry, SimpleXmlIteratorStorage::index($entry) + 1);
    }
}

/** SimpleXMLElement::hasChildren() — RecursiveIterator (php-src simplexml.c; #21887). */
final class SimpleXmlElementHasChildren extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('hasChildren');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('SimpleXMLElement::hasChildren() called without $this');
        }
        // php-src simplexml.stub.php: hasChildren(): bool (#30828).
        $this->requireExactUserArgCount($frame, 'SimpleXMLElement::hasChildren', 0);
        $entry = VmSimpleXml::requireElement(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLElement::hasChildren()'
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(SimpleXmlElementIterator::hasChildren($entry));
        }
    }
}

/** SimpleXMLElement::getChildren() — RecursiveIterator (php-src simplexml.c; #21887). */
final class SimpleXmlElementGetChildren extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getChildren');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('SimpleXMLElement::getChildren() requires VM context');
        }
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('SimpleXMLElement::getChildren() called without $this');
        }
        // php-src simplexml.stub.php: getChildren(): ?SimpleXMLElement (#30828).
        $this->requireExactUserArgCount($frame, 'SimpleXMLElement::getChildren', 0);
        $entry = VmSimpleXml::requireElement(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLElement::getChildren()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $child = SimpleXmlElementIterator::getChildren($frame->vmContext, $entry);
        if (null === $child) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->object($child);
    }
}
