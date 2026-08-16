<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * RecursiveTreeIterator — ASCII tree display over RecursiveIterator (php-src ext/spl/spl_iterators.c; #13223).
 */
final class RecursiveTreeIteratorBuiltin
{
    public const CLASS_LC = 'recursivetreeiterator';

    /** php-src RTIT_BYPASS_CURRENT — 0x00000004 */
    public const BYPASS_CURRENT = 0x00000004;

    /** php-src RTIT_BYPASS_KEY — 0x00000008 */
    public const BYPASS_KEY = 0x00000008;

    public static function registerClass(Context $ctx): void
    {
        RecursiveIteratorIteratorBuiltin::registerClass($ctx);

        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('RecursiveTreeIterator');
        $entry->parentLc = RecursiveIteratorIteratorBuiltin::CLASS_LC;
        // Zend rematerializes Iterator-first flattened ce->interfaces on the subclass
        // (RecursiveIteratorIterator parent stays OuterIterator-first; #25823).
        $entry->interfaces = [];
        foreach (['iterator', 'traversable', 'outeriterator'] as $iface) {
            if (isset($ctx->classes[$iface])) {
                $entry->interfaces[] = $iface;
            }
        }

        SplClassConstants::registerIntConstants($entry, [
            'BYPASS_KEY' => self::BYPASS_KEY,
            'BYPASS_CURRENT' => self::BYPASS_CURRENT,
            'PREFIX_LEFT' => 0,
            'PREFIX_MID_HAS_NEXT' => 1,
            'PREFIX_MID_LAST' => 2,
            'PREFIX_END_HAS_NEXT' => 3,
            'PREFIX_END_LAST' => 4,
            'PREFIX_RIGHT' => 5,
        ]);

        $entry->constructor = new RecursiveTreeIteratorConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        foreach ([
            'rewind' => RecursiveTreeIteratorRewind::class,
            'valid' => RecursiveTreeIteratorValid::class,
            'current' => RecursiveTreeIteratorCurrent::class,
            'key' => RecursiveTreeIteratorKey::class,
            'next' => RecursiveTreeIteratorNext::class,
            'getprefix' => RecursiveTreeIteratorGetPrefix::class,
            'setprefixpart' => RecursiveTreeIteratorSetPrefixPart::class,
            'getpostfix' => RecursiveTreeIteratorGetPostfix::class,
            'setpostfix' => RecursiveTreeIteratorSetPostfix::class,
            'getentry' => RecursiveTreeIteratorGetEntry::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->methodNames['getprefix'] = 'getPrefix';
        $entry->methodNames['setprefixpart'] = 'setPrefixPart';
        $entry->methodNames['getpostfix'] = 'getPostfix';
        $entry->methodNames['setpostfix'] = 'setPostfix';
        $entry->methodNames['getentry'] = 'getEntry';

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset($entry->methods['rewind'], $entry->methods['valid'], $entry->methods['__construct']);
    }
}

/** @internal */
final class SplTreeIteratorStorage
{
    /** @var array<int, array{flags: int, prefix: list<string>, postfix: string}> */
    private static array $store = [];

    public static function init(ObjectEntry $object, ObjectEntry $inner, int $mode): void
    {
        SplDualIteratorStorage::initRecursive($object, $inner, $mode);
        self::$store[$object->id] = [
            'flags' => RecursiveTreeIteratorBuiltin::BYPASS_KEY,
            // php-src default_prefix: "", "| ", "  ", "|-", "\\-", "" (#6273).
            'prefix' => ['', '| ', '  ', '|-', '\\-', ''],
            'postfix' => '',
        ];
    }

    public static function setFlags(ObjectEntry $object, int $flags): void
    {
        // Must write through $store — state() returns by value (#31649).
        self::$store[$object->id]['flags'] = $flags;
    }

    public static function flags(ObjectEntry $object): int
    {
        return self::state($object)['flags'];
    }

    public static function setPrefixPart(ObjectEntry $object, int $part, string $prefix): void
    {
        $state = &self::$store[$object->id];
        $state['prefix'][$part] = $prefix;
    }

    public static function setPostfix(ObjectEntry $object, string $postfix): void
    {
        self::$store[$object->id]['postfix'] = $postfix;
    }

    public static function getPrefix(Frame $frame, ObjectEntry $object): string
    {
        $state = self::state($object);
        $stack = SplDualIteratorStorage::iteratorStack($object);
        $level = max(0, \count($stack) - 1);
        $prefix = $state['prefix'][0];
        for ($i = 0; $i < $level; ++$i) {
            $prefix .= self::hasNextAtLevel($frame, $stack[$i])
                ? $state['prefix'][1]
                : $state['prefix'][2];
        }
        $prefix .= self::hasNextAtLevel($frame, $stack[$level] ?? null)
            ? $state['prefix'][3]
            : $state['prefix'][4];
        $prefix .= $state['prefix'][5];

        return $prefix;
    }

    public static function getPostfix(ObjectEntry $object): string
    {
        return self::state($object)['postfix'];
    }

    public static function getEntry(Frame $frame, ObjectEntry $object): ?string
    {
        $stack = SplDualIteratorStorage::iteratorStack($object);
        if ([] === $stack) {
            return null;
        }
        $current = SplDualIteratorStorage::callInner(
            $frame,
            $stack[\count($stack) - 1],
            'current'
        )->resolveIndirect();
        if (Variable::TYPE_ARRAY === $current->type) {
            return 'Array';
        }
        // php-src convert_to_string — invoke __toString on SplFileInfo etc. (#6273).
        if (Variable::TYPE_OBJECT === $current->type) {
            if (null === $frame->vmContext || null === $frame->vmContext->runtime) {
                return null;
            }

            return $frame->vmContext->runtime->vm->castObjectToString($current->toObject());
        }

        return $current->toString();
    }

    private static function hasNextAtLevel(Frame $frame, ?ObjectEntry $iterator): bool
    {
        if (null === $iterator) {
            return false;
        }
        if (null === $frame->vmContext || null === $frame->vmContext->runtime) {
            return false;
        }
        $vm = $frame->vmContext->runtime->vm;
        // Walk parent ClassEntry — RecursiveCachingIterator inherits hasNext (#6273).
        if (!$vm->hasInstanceMethod($iterator->class, 'hasnext')) {
            return false;
        }
        $result = SplDualIteratorStorage::callInner($frame, $iterator, 'hasNext')->resolveIndirect();

        return Variable::TYPE_BOOLEAN === $result->type && $result->toBool();
    }

    /** @return array{flags: int, prefix: list<string>, postfix: string} */
    private static function state(ObjectEntry $object): array
    {
        if (!isset(self::$store[$object->id])) {
            throw new \LogicException('RecursiveTreeIterator state missing');
        }

        return self::$store[$object->id];
    }
}

final class RecursiveTreeIteratorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveTreeIteratorBuiltin::CLASS_LC,
            'RecursiveTreeIterator::__construct()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'RecursiveTreeIterator::__construct() expects at least 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('RecursiveTreeIterator::__construct() requires VM context');
        }

        // php-src spl_recursive_it_it_construct RIT_RecursiveTreeIterator (#6273 / #31596):
        // unwrap IteratorAggregate, then object_init RecursiveCachingIterator — non-RecursiveIterator
        // surfaces as RCI TypeError, not RII InvalidArgumentException.
        $userInner = SplDualIteratorStorage::resolveRecursiveTreeIteratorInner(
            $frame->vmContext,
            $frame,
            $frame->calledArgs[1]
        );
        // flags, caching_it_flags, mode — wrap user iterator in RecursiveCachingIterator
        // so getPrefix() can call hasNext() on each stack level.
        $flags = RecursiveTreeIteratorBuiltin::BYPASS_KEY;
        $cachingFlags = CachingIteratorBuiltin::CATCH_GET_CHILD;
        $mode = IteratorIteratorBuiltin::SELF_FIRST;
        // php-src Z_PARAM_LONG — soft-null DEP+0 outside strict_types (#31649).
        if (isset($frame->calledArgs[2])) {
            $flags = VmMath::parseZParamLongBuiltinArgForFrame(
                $frame,
                2,
                'RecursiveTreeIterator::__construct',
                2,
                'flags'
            );
        }
        if (isset($frame->calledArgs[3])) {
            $cachingFlags = VmMath::parseZParamLongBuiltinArgForFrame(
                $frame,
                3,
                'RecursiveTreeIterator::__construct',
                3,
                'caching_it_flags'
            );
        }
        if (isset($frame->calledArgs[4])) {
            $mode = VmMath::parseZParamLongBuiltinArgForFrame(
                $frame,
                4,
                'RecursiveTreeIterator::__construct',
                4,
                'mode'
            );
        }

        $cachingVar = RecursiveCachingIteratorBuiltin::createFromInnerAndFlags(
            $frame->vmContext,
            $frame,
            $userInner,
            $cachingFlags
        )->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $cachingVar->type) {
            throw new \LogicException('RecursiveTreeIterator failed to wrap RecursiveCachingIterator');
        }

        SplTreeIteratorStorage::init($object, $cachingVar->toObject(), $mode);
        SplTreeIteratorStorage::setFlags($object, $flags);
    }
}

final class RecursiveTreeIteratorRewind extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveTreeIteratorBuiltin::CLASS_LC,
            'RecursiveTreeIterator::rewind()'
        );
        SplDualIteratorStorage::rewindRecursive($frame, $object);
    }
}

final class RecursiveTreeIteratorValid extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveTreeIteratorBuiltin::CLASS_LC,
            'RecursiveTreeIterator::valid()'
        );
        SplIteratorSupport::setReturnBool(
            $frame,
            SplDualIteratorStorage::validRecursive($frame, $object)
        );
    }
}

final class RecursiveTreeIteratorCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveTreeIteratorBuiltin::CLASS_LC,
            'RecursiveTreeIterator::current()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        if (0 !== (SplTreeIteratorStorage::flags($object) & RecursiveTreeIteratorBuiltin::BYPASS_CURRENT)) {
            SplIteratorSupport::copyReturnFrom(
                $frame,
                SplDualIteratorStorage::currentRecursive($frame, $object)
            );

            return;
        }
        $entry = SplTreeIteratorStorage::getEntry($frame, $object);
        if (null === $entry) {
            SplIteratorSupport::setReturnNull($frame);

            return;
        }
        $frame->returnVar->string(
            SplTreeIteratorStorage::getPrefix($frame, $object)
            .$entry
            .SplTreeIteratorStorage::getPostfix($object)
        );
    }
}

final class RecursiveTreeIteratorKey extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveTreeIteratorBuiltin::CLASS_LC,
            'RecursiveTreeIterator::key()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $key = SplDualIteratorStorage::keyRecursive($frame, $object);
        if (0 !== (SplTreeIteratorStorage::flags($object) & RecursiveTreeIteratorBuiltin::BYPASS_KEY)) {
            SplIteratorSupport::copyReturnFrom($frame, $key);

            return;
        }
        $keyStr = $key->resolveIndirect()->toString();
        $frame->returnVar->string(
            SplTreeIteratorStorage::getPrefix($frame, $object)
            .$keyStr
            .SplTreeIteratorStorage::getPostfix($object)
        );
    }
}

final class RecursiveTreeIteratorNext extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveTreeIteratorBuiltin::CLASS_LC,
            'RecursiveTreeIterator::next()'
        );
        SplDualIteratorStorage::nextRecursive($frame, $object);
    }
}

final class RecursiveTreeIteratorGetPrefix extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getPrefix');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveTreeIteratorBuiltin::CLASS_LC,
            'RecursiveTreeIterator::getPrefix()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(SplTreeIteratorStorage::getPrefix($frame, $object));
    }
}

final class RecursiveTreeIteratorSetPrefixPart extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setPrefixPart');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveTreeIteratorBuiltin::CLASS_LC,
            'RecursiveTreeIterator::setPrefixPart()'
        );
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'RecursiveTreeIterator::setPrefixPart() expects exactly 2 arguments, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $part = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $part->type) {
            throw new \TypeError(
                'RecursiveTreeIterator::setPrefixPart(): Argument #1 ($part) must be of type int, '
                .SplTreeIteratorArg::typeLabel($part).' given'
            );
        }
        $partInt = $part->toInt();
        if ($partInt < 0 || $partInt > 5) {
            throw new \ValueError(
                'RecursiveTreeIterator::setPrefixPart(): Argument #1 ($part) must be a RecursiveTreeIterator::PREFIX_* constant'
            );
        }
        $prefixArg = $frame->calledArgs[2]->resolveIndirect();
        if (Variable::TYPE_STRING !== $prefixArg->type) {
            throw new \TypeError(
                'RecursiveTreeIterator::setPrefixPart(): Argument #2 ($prefix) must be of type string, '
                .SplTreeIteratorArg::typeLabel($prefixArg).' given'
            );
        }
        SplTreeIteratorStorage::setPrefixPart($object, $partInt, $prefixArg->toString());
    }
}

final class RecursiveTreeIteratorGetPostfix extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getPostfix');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveTreeIteratorBuiltin::CLASS_LC,
            'RecursiveTreeIterator::getPostfix()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(SplTreeIteratorStorage::getPostfix($object));
    }
}

final class RecursiveTreeIteratorSetPostfix extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setPostfix');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveTreeIteratorBuiltin::CLASS_LC,
            'RecursiveTreeIterator::setPostfix()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'RecursiveTreeIterator::setPostfix() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $postfixArg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $postfixArg->type) {
            throw new \TypeError(
                'RecursiveTreeIterator::setPostfix(): Argument #1 ($postfix) must be of type string, '
                .SplTreeIteratorArg::typeLabel($postfixArg).' given'
            );
        }
        SplTreeIteratorStorage::setPostfix($object, $postfixArg->toString());
    }
}

final class RecursiveTreeIteratorGetEntry extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getEntry');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveTreeIteratorBuiltin::CLASS_LC,
            'RecursiveTreeIterator::getEntry()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $entry = SplTreeIteratorStorage::getEntry($frame, $object);
        if (null === $entry) {
            SplIteratorSupport::setReturnNull($frame);

            return;
        }
        $frame->returnVar->string($entry);
    }
}

/** @internal */
final class SplTreeIteratorArg
{
    public static function typeLabel(Variable $var): string
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
