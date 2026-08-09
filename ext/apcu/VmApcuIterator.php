<?php

declare(strict_types=1);

namespace PHPCompiler\ext\apcu;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;

/**
 * APCUIterator — PECL apcu user-cache Iterator (apc_iterator.c / apc_iterator.stub.php; #27877).
 *
 * In-memory over {@see VmApcu}; PHP-in-PHP only (no runtime/*.c).
 */
final class VmApcuIterator
{
    public const CLASS_LC = 'apcuiterator';

    /**
     * @var array<int, array{
     *   initialized: bool,
     *   format: int,
     *   list: int,
     *   search: null|string|list<string>,
     *   keys: list<string>,
     *   index: int,
     *   totals_ready: bool,
     *   total_hits: int,
     *   total_size: int,
     *   total_count: int
     * }>
     */
    private static array $state = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('APCUIterator');
        $entry->isInternal = true;
        if (isset($ctx->classes['iterator'])) {
            $entry->interfaces[] = 'iterator';
        }

        $entry->constructor = new ApcuIteratorConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methodNames['__construct'] = '__construct';

        foreach ([
            'rewind' => ApcuIteratorRewind::class,
            'next' => ApcuIteratorNext::class,
            'valid' => ApcuIteratorValid::class,
            'key' => ApcuIteratorKey::class,
            'current' => ApcuIteratorCurrent::class,
            'gettotalhits' => ApcuIteratorGetTotalHits::class,
            'gettotalsize' => ApcuIteratorGetTotalSize::class,
            'gettotalcount' => ApcuIteratorGetTotalCount::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
            $entry->methodNames[$lc] = match ($lc) {
                'gettotalhits' => 'getTotalHits',
                'gettotalsize' => 'getTotalSize',
                'gettotalcount' => 'getTotalCount',
                default => $lc,
            };
        }

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function isIteratorObject(ObjectEntry $object): bool
    {
        return self::CLASS_LC === \strtolower($object->class->name);
    }

    public static function receiver(Frame $frame, string $method): ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($method.' called without $this');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException($method.' called on non-object');
        }
        $object = $receiver->toObject();
        if (!self::isIteratorObject($object)) {
            throw new \LogicException($method.' called on incompatible object');
        }

        return $object;
    }

    /**
     * @param null|string|list<string> $search
     */
    public static function construct(
        ObjectEntry $object,
        null|string|array $search,
        int $format,
        int $list
    ): void {
        if ($format < 0 || $format > ApcuConstants::ITER_ALL) {
            throw new \ValueError('APCUIterator::__construct(): Argument #2 ($format) must be between 0 and '.ApcuConstants::ITER_ALL);
        }
        $object->constructed = true;
        self::$state[$object->id] = [
            'initialized' => true,
            'format' => $format,
            'list' => $list,
            'search' => $search,
            'keys' => [],
            'index' => 0,
            'totals_ready' => false,
            'total_hits' => 0,
            'total_size' => 0,
            'total_count' => 0,
        ];
        self::refetch($object);
    }

    public static function rewind(ObjectEntry $object): void
    {
        $st = &self::requireState($object);
        self::refetch($object);
        $st['index'] = 0;
    }

    public static function next(ObjectEntry $object): void
    {
        $st = &self::requireState($object);
        ++$st['index'];
    }

    public static function valid(ObjectEntry $object): bool
    {
        $st = self::requireState($object);

        return $st['index'] >= 0 && $st['index'] < \count($st['keys']);
    }

    public static function key(ObjectEntry $object): string|int
    {
        $st = self::requireState($object);
        if ($st['index'] < 0 || $st['index'] >= \count($st['keys'])) {
            throw new \Error('Cannot call key() on invalid iterator');
        }

        return $st['keys'][$st['index']];
    }

    public static function current(ObjectEntry $object): Variable
    {
        $st = self::requireState($object);
        if ($st['index'] < 0 || $st['index'] >= \count($st['keys'])) {
            throw new \Error('Cannot call current() on invalid iterator');
        }
        $key = $st['keys'][$st['index']];
        $entry = VmApcu::entrySnapshot($key);
        $ht = new HashTable();
        $format = $st['format'];

        if (0 !== ($format & ApcuConstants::ITER_TYPE)) {
            $slot = new Variable();
            $slot->string('user');
            $ht->add('type', $slot);
        }
        if (0 !== ($format & ApcuConstants::ITER_KEY)) {
            $slot = new Variable();
            $slot->string($key);
            $ht->add('key', $slot);
        }
        if (0 !== ($format & ApcuConstants::ITER_VALUE)) {
            $slot = new Variable();
            if (null !== $entry) {
                $slot->duplicateFrom($entry['value']);
            } else {
                $slot->null();
            }
            $ht->add('value', $slot);
        }
        if (0 !== ($format & ApcuConstants::ITER_NUM_HITS)) {
            $slot = new Variable();
            $slot->int(0);
            $ht->add('num_hits', $slot);
        }
        if (0 !== ($format & ApcuConstants::ITER_MTIME)) {
            $slot = new Variable();
            $slot->int(0);
            $ht->add('mtime', $slot);
        }
        if (0 !== ($format & ApcuConstants::ITER_CTIME)) {
            $slot = new Variable();
            $slot->int(0);
            $ht->add('creation_time', $slot);
        }
        if (0 !== ($format & ApcuConstants::ITER_DTIME)) {
            $slot = new Variable();
            $slot->int(0);
            $ht->add('deletion_time', $slot);
        }
        if (0 !== ($format & ApcuConstants::ITER_ATIME)) {
            $slot = new Variable();
            $slot->int(0);
            $ht->add('access_time', $slot);
        }
        if (0 !== ($format & ApcuConstants::ITER_REFCOUNT)) {
            $slot = new Variable();
            $slot->int(1);
            $ht->add('ref_count', $slot);
        }
        if (0 !== ($format & ApcuConstants::ITER_MEM_SIZE)) {
            $slot = new Variable();
            $slot->int(0);
            $ht->add('mem_size', $slot);
        }
        if (0 !== ($format & ApcuConstants::ITER_TTL)) {
            $ttl = 0;
            if (null !== $entry && $entry['expires'] > 0) {
                $ttl = \max(0, $entry['expires'] - \time());
            }
            $slot = new Variable();
            $slot->int($ttl);
            $ht->add('ttl', $slot);
        }

        $out = new Variable();
        $out->array($ht);

        return $out;
    }

    public static function getTotalHits(ObjectEntry $object): int
    {
        self::ensureTotals($object);

        return self::requireState($object)['total_hits'];
    }

    public static function getTotalSize(ObjectEntry $object): int
    {
        self::ensureTotals($object);

        return self::requireState($object)['total_size'];
    }

    public static function getTotalCount(ObjectEntry $object): int
    {
        self::ensureTotals($object);

        return self::requireState($object)['total_count'];
    }

    /**
     * @return array{
     *   initialized: bool,
     *   format: int,
     *   list: int,
     *   search: null|string|list<string>,
     *   keys: list<string>,
     *   index: int,
     *   totals_ready: bool,
     *   total_hits: int,
     *   total_size: int,
     *   total_count: int
     * }
     */
    private static function &requireState(ObjectEntry $object): array
    {
        if (!isset(self::$state[$object->id]) || !self::$state[$object->id]['initialized']) {
            throw new \Error('The object has not been correctly initialized by its constructor');
        }

        return self::$state[$object->id];
    }

    private static function refetch(ObjectEntry $object): void
    {
        $st = &self::requireState($object);
        // v1: only ACTIVE list is populated (in-process store has no deleted list).
        if (0 === ($st['list'] & ApcuConstants::LIST_ACTIVE)) {
            $st['keys'] = [];
            $st['totals_ready'] = false;

            return;
        }
        $all = VmApcu::listKeys();
        $matched = [];
        foreach ($all as $key) {
            if (self::searchMatch($st['search'], $key)) {
                $matched[] = $key;
            }
        }
        $st['keys'] = $matched;
        $st['totals_ready'] = false;
    }

    /**
     * @param null|string|list<string> $search
     */
    private static function searchMatch(null|string|array $search, string $key): bool
    {
        if (null === $search) {
            return true;
        }
        if (\is_array($search)) {
            return \in_array($key, $search, true);
        }
        // PECL: PCRE match against key (full pattern as given).
        $ok = @\preg_match($search, $key);
        if (false === $ok) {
            return false;
        }

        return $ok > 0;
    }

    private static function ensureTotals(ObjectEntry $object): void
    {
        $st = &self::requireState($object);
        if ($st['totals_ready']) {
            return;
        }
        // Snapshot matching keys without disturbing iteration cursor.
        $search = $st['search'];
        $list = $st['list'];
        $count = 0;
        if (0 !== ($list & ApcuConstants::LIST_ACTIVE)) {
            foreach (VmApcu::listKeys() as $key) {
                if (self::searchMatch($search, $key)) {
                    ++$count;
                }
            }
        }
        $st['total_count'] = $count;
        $st['total_hits'] = 0;
        $st['total_size'] = 0;
        $st['totals_ready'] = true;
    }
}

final class ApcuIteratorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = VmApcuIterator::receiver($frame, 'APCUIterator::__construct()');
        $argc = \count($frame->calledArgs);
        // calledArgs[0] is $this; optional search/format/chunk_size/list follow.
        $userArgc = $argc - 1;
        if ($userArgc > 4) {
            throw new \ArgumentCountError(
                'APCUIterator::__construct() expects at most 4 arguments, '.$userArgc.' given'
            );
        }

        $search = null;
        if ($userArgc >= 1) {
            $searchVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $searchVar->type) {
                if (Variable::TYPE_ARRAY === $searchVar->type) {
                    $keys = [];
                    foreach ($searchVar->toArray()->iterateKeyed(true) as [, $entry]) {
                        $keys[] = VmString::coerceZparamStrBuiltinArg(
                            $entry,
                            'APCUIterator::__construct',
                            1,
                            'search'
                        );
                    }
                    $search = $keys;
                } else {
                    $search = VmString::coerceZparamStrBuiltinArg(
                        $searchVar,
                        'APCUIterator::__construct',
                        1,
                        'search'
                    );
                }
            }
        }

        $format = ApcuConstants::ITER_ALL;
        if ($userArgc >= 2) {
            $format = VmMath::parseIntBuiltinArgForFrame(
                $frame,
                2,
                'APCUIterator::__construct',
                2,
                'format'
            );
        }

        // chunk_size accepted for arity/PECL parity; in-memory store snapshots all matches.
        if ($userArgc >= 3) {
            VmMath::parseIntBuiltinArgForFrame(
                $frame,
                3,
                'APCUIterator::__construct',
                3,
                'chunk_size'
            );
        }

        $list = ApcuConstants::LIST_ACTIVE;
        if ($userArgc >= 4) {
            $list = VmMath::parseIntBuiltinArgForFrame(
                $frame,
                4,
                'APCUIterator::__construct',
                4,
                'list'
            );
        }

        VmApcuIterator::construct($object, $search, $format, $list);
    }
}

final class ApcuIteratorRewind extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        VmApcuIterator::rewind(VmApcuIterator::receiver($frame, 'APCUIterator::rewind()'));
    }
}

final class ApcuIteratorNext extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        VmApcuIterator::next(VmApcuIterator::receiver($frame, 'APCUIterator::next()'));
    }
}

final class ApcuIteratorValid extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        $object = VmApcuIterator::receiver($frame, 'APCUIterator::valid()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmApcuIterator::valid($object));
    }
}

final class ApcuIteratorKey extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        $object = VmApcuIterator::receiver($frame, 'APCUIterator::key()');
        if (null === $frame->returnVar) {
            return;
        }
        $key = VmApcuIterator::key($object);
        if (\is_int($key)) {
            $frame->returnVar->int($key);
        } else {
            $frame->returnVar->string($key);
        }
    }
}

final class ApcuIteratorCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        $object = VmApcuIterator::receiver($frame, 'APCUIterator::current()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->duplicateFrom(VmApcuIterator::current($object));
    }
}

final class ApcuIteratorGetTotalHits extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getTotalHits');
    }

    public function execute(Frame $frame): void
    {
        $object = VmApcuIterator::receiver($frame, 'APCUIterator::getTotalHits()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmApcuIterator::getTotalHits($object));
    }
}

final class ApcuIteratorGetTotalSize extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getTotalSize');
    }

    public function execute(Frame $frame): void
    {
        $object = VmApcuIterator::receiver($frame, 'APCUIterator::getTotalSize()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmApcuIterator::getTotalSize($object));
    }
}

final class ApcuIteratorGetTotalCount extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getTotalCount');
    }

    public function execute(Frame $frame): void
    {
        $object = VmApcuIterator::receiver($frame, 'APCUIterator::getTotalCount()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmApcuIterator::getTotalCount($object));
    }
}
