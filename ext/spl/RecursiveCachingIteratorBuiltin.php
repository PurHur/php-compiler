<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\InterfaceCheck;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * RecursiveCachingIterator — CachingIterator + RecursiveIterator (php-src ext/spl/spl_iterators.c; #6915).
 */
final class RecursiveCachingIteratorBuiltin
{
    public const CLASS_LC = 'recursivecachingiterator';

    public static function registerClass(Context $ctx): void
    {
        CachingIteratorBuiltin::registerClass($ctx);
        if (isset($ctx->classes['recursiveiterator']) === false) {
            // Defer until VmSplIterators registers RecursiveIterator.
            return;
        }

        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('RecursiveCachingIterator');
        $entry->parentLc = CachingIteratorBuiltin::CLASS_LC;
        foreach ([
            'countable',
            'arrayaccess',
            'outeriterator',
            'traversable',
            'iterator',
            'stringable',
            'recursiveiterator',
        ] as $ifaceLc) {
            if (isset($ctx->classes[$ifaceLc]) && !\in_array($ifaceLc, $entry->interfaces, true)) {
                $entry->interfaces[] = $ifaceLc;
            }
        }

        $entry->constructor = new RecursiveCachingIteratorConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['haschildren'] = new RecursiveCachingIteratorHasChildren();
        $entry->methodVisibility['haschildren'] = $pub;
        $entry->methodNames['haschildren'] = 'hasChildren';
        $entry->methods['getchildren'] = new RecursiveCachingIteratorGetChildren();
        $entry->methodVisibility['getchildren'] = $pub;
        $entry->methodNames['getchildren'] = 'getChildren';

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset($entry->methods['__construct'], $entry->methods['haschildren'], $entry->methods['getchildren'])
            && $entry->constructor instanceof RecursiveCachingIteratorConstruct;
    }

    public static function requireRecursiveIteratorArg(
        Variable $var,
        string $function,
        int $argIndex,
        Context $ctx
    ): ObjectEntry {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $resolved->type) {
            throw new \TypeError(
                $function.'(): Argument #'.$argIndex.' ($iterator) must be of type RecursiveIterator, '
                .self::typeLabel($resolved).' given'
            );
        }
        $object = $resolved->toObject();
        if (!InterfaceCheck::entryImplements($object->class, 'recursiveiterator', $ctx)) {
            throw new \TypeError(
                $function.'(): Argument #'.$argIndex.' ($iterator) must be of type RecursiveIterator, '
                .$object->class->name.' given'
            );
        }

        return $object;
    }

    public static function createFromInnerAndFlags(
        Context $ctx,
        Frame $frame,
        ObjectEntry $inner,
        int $flags
    ): Variable {
        $class = $ctx->classes[self::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('RecursiveCachingIterator is not registered in this compiler build');
        }
        $object = new ObjectEntry($class);
        $object->constructed = true;
        // Match CachingIterator: no construct-time rewind (#22876).
        SplCachingIteratorStorage::init($object, $inner, $flags);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }

    private static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'mixed',
        };
    }
}

final class RecursiveCachingIteratorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            RecursiveCachingIteratorBuiltin::CLASS_LC,
            'RecursiveCachingIterator::__construct()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'RecursiveCachingIterator::__construct() expects at least 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('RecursiveCachingIterator::__construct() requires VM context');
        }
        $inner = RecursiveCachingIteratorBuiltin::requireRecursiveIteratorArg(
            $frame->calledArgs[1],
            'RecursiveCachingIterator::__construct',
            1,
            $frame->vmContext
        );
        // Same default/null semantics as CachingIterator (#22336 / #31679; php-src spl.stub.php).
        $flags = CachingIteratorConstruct::resolveConstructFlags(
            $frame->calledArgs[2] ?? null,
            'RecursiveCachingIterator::__construct',
            $frame
        );
        // php-src spl_cit_check_flags — method name is RecursiveCachingIterator (#31551).
        CachingIteratorBuiltin::assertExclusiveToStringFlags(
            $flags,
            'RecursiveCachingIterator::__construct',
            2
        );
        // Match CachingIterator: no construct-time rewind (#22876).
        SplCachingIteratorStorage::init($object, $inner, $flags);
    }
}

final class RecursiveCachingIteratorHasChildren extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('hasChildren');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            RecursiveCachingIteratorBuiltin::CLASS_LC,
            'RecursiveCachingIterator::hasChildren()'
        );
        $inner = SplCachingIteratorStorage::inner($object);
        $has = SplDualIteratorStorage::callInner($frame, $inner, 'hasChildren')->resolveIndirect();
        SplIteratorSupport::setReturnBool(
            $frame,
            Variable::TYPE_BOOLEAN === $has->type && $has->toBool()
        );
    }
}

final class RecursiveCachingIteratorGetChildren extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getChildren');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            RecursiveCachingIteratorBuiltin::CLASS_LC,
            'RecursiveCachingIterator::getChildren()'
        );
        if (null === $frame->returnVar || null === $frame->vmContext) {
            return;
        }
        $inner = SplCachingIteratorStorage::inner($object);
        $childInner = SplDualIteratorStorage::callInner($frame, $inner, 'getChildren')->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $childInner->type) {
            $frame->returnVar->null();

            return;
        }
        SplIteratorSupport::copyReturnFrom(
            $frame,
            RecursiveCachingIteratorBuiltin::createFromInnerAndFlags(
                $frame->vmContext,
                $frame,
                $childInner->toObject(),
                SplCachingIteratorStorage::flags($object)
            )
        );
    }
}
