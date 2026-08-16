<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** SimpleXMLIterator — recursive child iterator (php-src ext/simplexml/sxe.c; #6694). */
final class VmSimpleXmlIterator
{
    public const CLASS_LC = 'simplexmliterator';

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('SimpleXMLIterator');
        $entry->parentLc = VmSimpleXml::CLASS_LC;
        foreach (['Iterator', 'RecursiveIterator', 'Countable', 'Traversable'] as $iface) {
            if (isset($ctx->classes[strtolower($iface)])) {
                $entry->interfaces[] = $iface;
            }
        }

        $entry->constructor = new SimpleXmlIteratorConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        foreach ([
            'current' => SimpleXmlIteratorCurrent::class,
            'key' => SimpleXmlIteratorKey::class,
            'next' => SimpleXmlIteratorNext::class,
            'rewind' => SimpleXmlIteratorRewind::class,
            'valid' => SimpleXmlIteratorValid::class,
            'count' => SimpleXmlIteratorCount::class,
            // hasChildren/getChildren inherited from SimpleXMLElement (php-src empty subclass; #21887).
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function requireIterator(ObjectEntry $entry, string $label): ObjectEntry
    {
        if (self::CLASS_LC !== strtolower($entry->class->name)) {
            throw new \TypeError(sprintf('%s(): Argument must be SimpleXMLIterator, %s given', $label, $entry->class->name));
        }
        if (!SimpleXmlRegistry::has($entry)) {
            throw new \LogicException($label.'(): SimpleXMLIterator has no node state');
        }

        return $entry;
    }

    /** @return list<SimpleXmlNodeState> */
    public static function iterableChildren(ObjectEntry $entry): array
    {
        return VmSimpleXml::directElementChildren($entry);
    }

    public static function wrapChild(Context $ctx, ObjectEntry $iterator, int $index): ObjectEntry
    {
        $children = self::iterableChildren($iterator);
        if (!isset($children[$index])) {
            throw new \LogicException('SimpleXMLIterator child index out of range');
        }
        if (SimpleXmlRegistry::isAttributesView($iterator)) {
            return VmSimpleXml::wrapAttributeNode($ctx, $iterator, $children[$index]->name);
        }
        $class = $ctx->classes[self::CLASS_LC] ?? $iterator->class;

        return VmSimpleXml::wrapIteratorNode(
            $ctx,
            $class,
            $children[$index],
            SimpleXmlRegistry::documentKey($iterator)
        );
    }
}

final class SimpleXmlIteratorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('SimpleXMLIterator::__construct() requires VM context');
        }
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('SimpleXMLIterator::__construct() expects at least 1 argument, 0 given');
        }
        $iterator = $frame->calledArgs[0]->resolveIndirect()->toObject();
        if (VmSimpleXmlIterator::CLASS_LC !== strtolower($iterator->class->name)) {
            throw new \LogicException('SimpleXMLIterator::__construct() called on invalid receiver');
        }
        // php-src: SimpleXMLIterator inherits SimpleXMLElement::__construct(string $data, …)
        // (#22406 / sxe.c). Soft-null $data matches SimpleXMLElement (#31514).
        $data = VmString::stringBuiltinArgForFrame(
            $frame,
            1,
            'SimpleXMLIterator::__construct',
            0,
            'data',
            false
        );
        VmSimpleXml::constructFromData(
            $frame->vmContext,
            $iterator,
            $data,
            $frame
        );
    }
}

final class SimpleXmlIteratorCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('SimpleXMLIterator::current() requires VM context');
        }
        $iterator = VmSimpleXmlIterator::requireIterator(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLIterator::current()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        if (!self::validIterator($iterator)) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->object(VmSimpleXmlIterator::wrapChild(
            $frame->vmContext,
            $iterator,
            SimpleXmlIteratorStorage::index($iterator)
        ));
    }

    private static function validIterator(ObjectEntry $iterator): bool
    {
        $index = SimpleXmlIteratorStorage::index($iterator);

        return $index >= 0 && $index < \count(VmSimpleXmlIterator::iterableChildren($iterator));
    }
}

final class SimpleXmlIteratorKey extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        $iterator = VmSimpleXmlIterator::requireIterator(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLIterator::key()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $children = VmSimpleXmlIterator::iterableChildren($iterator);
        $index = SimpleXmlIteratorStorage::index($iterator);
        if ($index < 0 || $index >= \count($children)) {
            $frame->returnVar->null();

            return;
        }
        // php-src sxe.c iterator key: local name, not prefixed QName (#20136).
        $frame->returnVar->string(VmSimpleXml::localNameFromQualified($children[$index]->name));
    }
}

final class SimpleXmlIteratorNext extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        $iterator = VmSimpleXmlIterator::requireIterator(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLIterator::next()'
        );
        SimpleXmlIteratorStorage::setIndex($iterator, SimpleXmlIteratorStorage::index($iterator) + 1);
    }
}

final class SimpleXmlIteratorRewind extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        $iterator = VmSimpleXmlIterator::requireIterator(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLIterator::rewind()'
        );
        SimpleXmlIteratorStorage::rewind($iterator);
    }
}

final class SimpleXmlIteratorValid extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        $iterator = VmSimpleXmlIterator::requireIterator(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLIterator::valid()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $index = SimpleXmlIteratorStorage::index($iterator);
        $frame->returnVar->bool($index >= 0 && $index < \count(VmSimpleXmlIterator::iterableChildren($iterator)));
    }
}

final class SimpleXmlIteratorCount extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('count');
    }

    public function execute(Frame $frame): void
    {
        $iterator = VmSimpleXmlIterator::requireIterator(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLIterator::count()'
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(\count(VmSimpleXmlIterator::iterableChildren($iterator)));
        }
    }
}
