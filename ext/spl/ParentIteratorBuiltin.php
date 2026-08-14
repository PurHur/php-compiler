<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * ParentIterator — iterate parents only (php-src ext/spl/spl_iterators.c; #13211).
 */
final class ParentIteratorBuiltin
{
    public const CLASS_LC = 'parentiterator';

    public static function registerClass(Context $ctx): void
    {
        FilterIteratorBuiltin::registerClass($ctx);

        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('ParentIterator');
        $entry->parentLc = RecursiveFilterIteratorBuiltin::CLASS_LC;
        // Zend rematerialized flattened ce->interfaces (#25798).
        $entry->interfaces = [];
        foreach (['recursiveiterator', 'iterator', 'traversable', 'outeriterator'] as $iface) {
            if (isset($ctx->classes[$iface])) {
                $entry->interfaces[] = $iface;
            }
        }

        $entry->constructor = new ParentIteratorConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        // php-src — public function accept(): bool (#28560).
        $entry->methods['accept'] = new ParentIteratorAccept();
        $entry->methodVisibility['accept'] = $pub;
        foreach ([
            'rewind' => ParentIteratorRewind::class,
            'valid' => ParentIteratorValid::class,
            'current' => ParentIteratorCurrent::class,
            'key' => ParentIteratorKey::class,
            'next' => ParentIteratorNext::class,
            'getinneriterator' => ParentIteratorGetInnerIterator::class,
            'haschildren' => ParentIteratorHasChildren::class,
            'getchildren' => ParentIteratorGetChildren::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->methodNames['getinneriterator'] = 'getInnerIterator';
        $entry->methodNames['haschildren'] = 'hasChildren';
        $entry->methodNames['getchildren'] = 'getChildren';

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    /**
     * ParentIterator::getChildren() wraps the inner child in a new ParentIterator
     * (php-src spl_iterators.c — spl_ParentIterator_get_children; #19784).
     */
    public static function createFromInner(Context $ctx, ObjectEntry $childInner): Variable
    {
        $class = $ctx->classes[self::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('ParentIterator is not registered in this compiler build');
        }
        $object = new ObjectEntry($class);
        $object->constructed = true;
        SplDualIteratorStorage::initSimple($object, $childInner);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset($entry->methods['rewind'], $entry->methods['accept'], $entry->methods['__construct']);
    }
}

final class ParentIteratorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ParentIteratorBuiltin::CLASS_LC,
            'ParentIterator::__construct()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'ParentIterator::__construct() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('ParentIterator::__construct() requires VM context');
        }
        // php-src ParentIterator::__construct(RecursiveIterator $iterator) — typed arg → TypeError
        // (zend_type_error), not the InvalidArgumentException used by RecursiveIteratorIterator.
        $inner = RecursiveCachingIteratorBuiltin::requireRecursiveIteratorArg(
            $frame->calledArgs[1],
            'ParentIterator::__construct',
            1,
            $frame->vmContext
        );
        SplDualIteratorStorage::initSimple($object, $inner);
    }
}

final class ParentIteratorAccept extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('accept');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ParentIteratorBuiltin::CLASS_LC,
            'ParentIterator::accept()'
        );
        // php-src zim_ParentIterator_accept — ZEND_PARSE_PARAMETERS_NONE (#30956).
        $this->requireExactUserArgCount($frame, 'ParentIterator::accept', 0);
        $inner = SplDualIteratorStorage::inner($object);
        $result = SplDualIteratorStorage::callInner($frame, $inner, 'hasChildren')->resolveIndirect();
        SplIteratorSupport::setReturnBool(
            $frame,
            Variable::TYPE_BOOLEAN === $result->type && $result->toBool()
        );
    }
}

final class ParentIteratorRewind extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ParentIteratorBuiltin::CLASS_LC,
            'ParentIterator::rewind()'
        );
        SplDualIteratorStorage::rewindSimple($frame, $object);
        FilterIteratorBuiltin::fetch($frame, $object);
    }
}

final class ParentIteratorNext extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ParentIteratorBuiltin::CLASS_LC,
            'ParentIterator::next()'
        );
        $inner = SplDualIteratorStorage::inner($object);
        SplDualIteratorStorage::callInner($frame, $inner, 'next');
        FilterIteratorBuiltin::fetch($frame, $object);
    }
}

final class ParentIteratorValid extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ParentIteratorBuiltin::CLASS_LC,
            'ParentIterator::valid()'
        );
        SplIteratorSupport::setReturnBool($frame, SplDualIteratorStorage::validSimple($frame, $object));
    }
}

final class ParentIteratorCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ParentIteratorBuiltin::CLASS_LC,
            'ParentIterator::current()'
        );
        SplIteratorSupport::copyReturnFrom($frame, SplDualIteratorStorage::currentSimple($frame, $object));
    }
}

final class ParentIteratorKey extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ParentIteratorBuiltin::CLASS_LC,
            'ParentIterator::key()'
        );
        SplIteratorSupport::copyReturnFrom($frame, SplDualIteratorStorage::keySimple($frame, $object));
    }
}

final class ParentIteratorGetInnerIterator extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getInnerIterator');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ParentIteratorBuiltin::CLASS_LC,
            'ParentIterator::getInnerIterator()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $inner = SplDualIteratorStorage::inner($object);
        $frame->returnVar->object($inner);
    }
}

final class ParentIteratorHasChildren extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('hasChildren');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ParentIteratorBuiltin::CLASS_LC,
            'ParentIterator::hasChildren()'
        );
        // php-src inherits RecursiveFilterIterator::hasChildren — ZEND_PARSE_PARAMETERS_NONE;
        // ACE cites the declaring class, not ParentIterator (#30956).
        $this->requireExactUserArgCount($frame, 'RecursiveFilterIterator::hasChildren', 0);
        $inner = SplDualIteratorStorage::inner($object);
        $result = SplDualIteratorStorage::callInner($frame, $inner, 'hasChildren')->resolveIndirect();
        SplIteratorSupport::setReturnBool(
            $frame,
            Variable::TYPE_BOOLEAN === $result->type && $result->toBool()
        );
    }
}

final class ParentIteratorGetChildren extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getChildren');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ParentIteratorBuiltin::CLASS_LC,
            'ParentIterator::getChildren()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('ParentIterator::getChildren() requires VM context');
        }
        $inner = SplDualIteratorStorage::inner($object);
        $child = SplDualIteratorStorage::callInner($frame, $inner, 'getChildren')->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $child->type) {
            throw new \UnexpectedValueException('RecursiveIterator::getChildren() must return an object');
        }
        $frame->returnVar->copyFrom(
            ParentIteratorBuiltin::createFromInner($frame->vmContext, $child->toObject())
        );
    }
}
