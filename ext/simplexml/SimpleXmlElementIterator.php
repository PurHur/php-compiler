<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/** SimpleXMLElement Iterator/Traversable (php-src ext/simplexml/sxe.c; #19089). */
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
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
    }

    public static function registerInterfaces(\PHPCompiler\VM\ClassEntry $entry, Context $ctx): void
    {
        foreach (['Iterator', 'Traversable'] as $iface) {
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
        $class = $ctx->classes[VmSimpleXml::CLASS_LC] ?? $entry->class;

        return VmSimpleXml::wrapIteratorNode(
            $ctx,
            $class,
            $children[$index],
            SimpleXmlRegistry::documentKey($entry)
        );
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
