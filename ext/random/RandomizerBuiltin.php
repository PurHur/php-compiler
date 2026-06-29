<?php

declare(strict_types=1);

namespace PHPCompiler\ext\random;

use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/** Per-object engine state for Random\Engine\* (#13191). */
final class RandomEngineStorage
{
    /** @var array<int, Mt19937Instance> */
    private static array $mt19937 = [];

    public static function attachMt19937(ObjectEntry $object, Mt19937Instance $engine): void
    {
        self::$mt19937[$object->id] = $engine;
    }

    public static function mt19937(ObjectEntry $object): Mt19937Instance
    {
        $engine = self::tryMt19937($object);
        if (null === $engine) {
            throw new \LogicException('Random engine state missing');
        }

        return $engine;
    }

    public static function tryMt19937(ObjectEntry $object): ?Mt19937Instance
    {
        return self::$mt19937[$object->id] ?? null;
    }

    public static function engineObject(ObjectEntry $randomizer): ObjectEntry
    {
        $engineVar = RandomizerStorage::engine($randomizer);
        if (Variable::TYPE_OBJECT !== $engineVar->type) {
            throw new \Random\RandomError('Engine must be an object');
        }

        return $engineVar->toObject();
    }

    public static function generate(ObjectEntry $engineObject): int
    {
        $lc = strtolower(ltrim($engineObject->class->name, '\\'));
        if ('random\\engine\\mt19937' === $lc) {
            return self::mt19937($engineObject)->generate();
        }

        throw new \LogicException('Unsupported random engine: '.$engineObject->class->name);
    }

    public static function range(ObjectEntry $engineObject, int $min, int $max): int
    {
        $lc = strtolower(ltrim($engineObject->class->name, '\\'));
        if ('random\\engine\\mt19937' === $lc) {
            return self::mt19937($engineObject)->range($min, $max);
        }

        throw new \LogicException('Unsupported random engine: '.$engineObject->class->name);
    }
}

/** Randomizer engine property storage (#13191). */
final class RandomizerStorage
{
    public static function setEngine(ObjectEntry $randomizer, Variable $engine): void
    {
        $randomizer->getProperty('engine')->copyFrom($engine->resolveIndirect());
    }

    public static function engine(ObjectEntry $randomizer): Variable
    {
        return $randomizer->getProperty('engine');
    }
}

/**
 * Random\Randomizer + Random\Engine\Mt19937 VM builtins (php-src ext/random/randomizer.c; #13191).
 */
final class RandomizerBuiltin
{
    public const RANDOMIZER_LC = 'random\\randomizer';

    public const MT19937_LC = 'random\\engine\\mt19937';

    public static function registerClasses(Context $ctx): void
    {
        self::registerMt19937($ctx);
        self::registerRandomizer($ctx);
    }

    private static function registerMt19937(Context $ctx): void
    {
        if (isset($ctx->classes[self::MT19937_LC]) && self::mt19937IsComplete($ctx->classes[self::MT19937_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::MT19937_LC])
            ? $ctx->classes[self::MT19937_LC]
            : new ClassEntry('Random\\Engine\\Mt19937');

        $entry->constructor = new Mt19937Construct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;

        foreach ([
            'generate' => Mt19937Generate::class,
            '__serialize' => Mt19937Serialize::class,
            '__unserialize' => Mt19937Unserialize::class,
            '__debuginfo' => Mt19937DebugInfo::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }

        $ctx->classes[self::MT19937_LC] = $entry;
    }

    private static function registerRandomizer(Context $ctx): void
    {
        if (isset($ctx->classes[self::RANDOMIZER_LC]) && self::randomizerIsComplete($ctx->classes[self::RANDOMIZER_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::RANDOMIZER_LC])
            ? $ctx->classes[self::RANDOMIZER_LC]
            : new ClassEntry('Random\\Randomizer');

        $entry->properties[] = new \PHPCompiler\VM\ClassProperty('engine', null, new Variable(Variable::TYPE_OBJECT), true);

        $entry->constructor = new RandomizerConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;

        foreach ([
            'nextint' => RandomizerNextInt::class,
            'getint' => RandomizerGetInt::class,
            'getbytes' => RandomizerGetBytes::class,
            'shufflearray' => RandomizerShuffleArray::class,
            'shufflebytes' => RandomizerShuffleBytes::class,
            'pickarraykeys' => RandomizerPickArrayKeys::class,
            '__serialize' => RandomizerSerialize::class,
            '__unserialize' => RandomizerUnserialize::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }

        $ctx->classes[self::RANDOMIZER_LC] = $entry;
    }

    private static function mt19937IsComplete(ClassEntry $entry): bool
    {
        return isset($entry->methods['generate'], $entry->methods['__construct']);
    }

    private static function randomizerIsComplete(ClassEntry $entry): bool
    {
        return isset(
            $entry->methods['getint'],
            $entry->methods['nextint'],
            $entry->methods['__construct'],
            $entry->methods['__serialize'],
            $entry->methods['__unserialize']
        );
    }

    public static function receiverRandomizer(Frame $frame, string $method): ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($method.' called without $this');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException($method.' called on non-object');
        }
        $object = $receiver->toObject();
        if (strtolower(ltrim($object->class->name, '\\')) !== self::RANDOMIZER_LC) {
            throw new \LogicException($method.' called on incompatible object');
        }

        return $object;
    }

    public static function receiverMt19937(Frame $frame, string $method): ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($method.' called without $this');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException($method.' called on non-object');
        }
        $object = $receiver->toObject();
        if (strtolower(ltrim($object->class->name, '\\')) !== self::MT19937_LC) {
            throw new \LogicException($method.' called on incompatible object');
        }

        return $object;
    }
}

final class Mt19937Construct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = RandomizerBuiltin::receiverMt19937($frame, 'Random\\Engine\\Mt19937::__construct()');
        $seed = null;
        if (isset($frame->calledArgs[1])) {
            $arg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $arg->type) {
                if (Variable::TYPE_STRING === $arg->type) {
                    $seed = (int) $arg->toString();
                } elseif (Variable::TYPE_INTEGER === $arg->type) {
                    $seed = $arg->toInt();
                } else {
                    throw new \TypeError(
                        'Random\\Engine\\Mt19937::__construct(): Argument #1 ($seed) must be of type int|string|null, '
                        .EnumCaseSupport::typeNameForVariable($arg).' given'
                    );
                }
            }
        }
        $mode = Mt19937Instance::MT_RAND_MT19937;
        if (isset($frame->calledArgs[2])) {
            $modeArg = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[2],
                'Random\\Engine\\Mt19937::__construct',
                1,
                'mode'
            );
            $mode = Mt19937Instance::MT_RAND_PHP === $modeArg
                ? Mt19937Instance::MT_RAND_PHP
                : Mt19937Instance::MT_RAND_MT19937;
        }

        $engine = new Mt19937Instance();
        if (null === $seed) {
            try {
                $bytes = VmString::randomBytes(8);
                $seed = \unpack('P', $bytes)[1];
                if (!\is_int($seed)) {
                    $seed = \time();
                }
            } catch (\Throwable) {
                $seed = \time();
            }
        }
        $engine->seed($seed & 0xFFFFFFFF, $mode);
        RandomEngineStorage::attachMt19937($object, $engine);
    }
}

final class Mt19937Generate extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('generate');
    }

    public function execute(Frame $frame): void
    {
        $object = RandomizerBuiltin::receiverMt19937($frame, 'Random\\Engine\\Mt19937::generate()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(RandomEngineStorage::mt19937($object)->generate());
    }
}

final class Mt19937Serialize extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__serialize');
    }

    public function execute(Frame $frame): void
    {
        $object = RandomizerBuiltin::receiverMt19937($frame, 'Random\\Engine\\Mt19937::__serialize()');
        if (null === $frame->returnVar) {
            return;
        }

        $engine = RandomEngineStorage::mt19937($object);
        $payload = [
            0 => [],
            1 => $engine->exportSerializedState(),
        ];
        $frame->returnVar->copyFrom(VmJson::import($payload));
    }
}

final class Mt19937Unserialize extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__unserialize');
    }

    public function execute(Frame $frame): void
    {
        $object = RandomizerBuiltin::receiverMt19937($frame, 'Random\\Engine\\Mt19937::__unserialize()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'Random\\Engine\\Mt19937::__unserialize() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $arg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $arg->type) {
            throw new \TypeError(
                'Random\\Engine\\Mt19937::__unserialize(): Argument #1 ($data) must be of type array'
            );
        }

        $stateVar = null;
        foreach ($arg->toArray()->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $key = $keyVar->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $key->type) {
                throw new \TypeError('Random\\Engine\\Mt19937::__unserialize(): invalid array key');
            }
            if (1 === $key->toInt()) {
                $stateVar = $valueVar->resolveIndirect();
            }
        }
        if (null === $stateVar || Variable::TYPE_ARRAY !== $stateVar->type) {
            throw new \TypeError('Random\\Engine\\Mt19937::__unserialize(): invalid serialized state');
        }

        $statePayload = VmJson::export($stateVar);

        $engine = RandomEngineStorage::tryMt19937($object) ?? new Mt19937Instance();
        RandomEngineStorage::attachMt19937($object, $engine);
        if (!\is_array($statePayload)) {
            throw new \TypeError('Random\\Engine\\Mt19937::__unserialize(): invalid serialized state');
        }
        $engine->restoreFromSerializedState($statePayload);
        $object->constructed = true;
    }
}

final class Mt19937DebugInfo extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__debugInfo');
    }

    public function execute(Frame $frame): void
    {
        RandomizerBuiltin::receiverMt19937($frame, 'Random\\Engine\\Mt19937::__debugInfo()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array([]);
    }
}

final class RandomizerConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = RandomizerBuiltin::receiverRandomizer($frame, 'Random\\Randomizer::__construct()');
        if (!isset($frame->calledArgs[1])) {
            $engineObject = self::createDefaultEngineObject($frame);
            $engineVar = new Variable(Variable::TYPE_OBJECT);
            $engineVar->object($engineObject);
            RandomizerStorage::setEngine($object, $engineVar);

            return;
        }
        $engineArg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_NULL === $engineArg->type) {
            $engineObject = self::createDefaultEngineObject($frame);
            $engineVar = new Variable(Variable::TYPE_OBJECT);
            $engineVar->object($engineObject);
            RandomizerStorage::setEngine($object, $engineVar);

            return;
        }
        if (Variable::TYPE_OBJECT !== $engineArg->type) {
            throw new \TypeError(
                'Random\\Randomizer::__construct(): Argument #1 ($engine) must be of type ?Random\\Engine, '
                .EnumCaseSupport::typeNameForVariable($engineArg).' given'
            );
        }
        RandomizerStorage::setEngine($object, $frame->calledArgs[1]);
    }

    private static function createDefaultEngineObject(Frame $frame): ObjectEntry
    {
        $engine = new Mt19937Instance();
        try {
            $bytes = VmString::randomBytes(8);
            $seed = \unpack('P', $bytes)[1];
            if (!\is_int($seed)) {
                $seed = \time();
            }
        } catch (\Throwable) {
            $seed = \time();
        }
        $engine->seed($seed & 0xFFFFFFFF);
        $engineObject = new ObjectEntry($frame->vmContext->classes[RandomizerBuiltin::MT19937_LC]);
        $engineObject->constructed = true;
        RandomEngineStorage::attachMt19937($engineObject, $engine);

        return $engineObject;
    }
}

final class RandomizerNextInt extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('nextInt');
    }

    public function execute(Frame $frame): void
    {
        $object = RandomizerBuiltin::receiverRandomizer($frame, 'Random\\Randomizer::nextInt()');
        if (null === $frame->returnVar) {
            return;
        }
        $engine = RandomEngineStorage::engineObject($object);
        $frame->returnVar->int(RandomEngineStorage::generate($engine));
    }
}

final class RandomizerGetInt extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getInt');
    }

    public function execute(Frame $frame): void
    {
        $object = RandomizerBuiltin::receiverRandomizer($frame, 'Random\\Randomizer::getInt()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'Random\\Randomizer::getInt() expects at least 2 arguments, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $min = VmMath::parseIntBuiltinArg($frame->calledArgs[1], 'Random\\Randomizer::getInt', 0, 'min');
        $max = VmMath::parseIntBuiltinArg($frame->calledArgs[2], 'Random\\Randomizer::getInt', 1, 'max');
        $engine = RandomEngineStorage::engineObject($object);
        $frame->returnVar->int(RandomEngineStorage::range($engine, $min, $max));
    }
}

final class RandomizerGetBytes extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getBytes');
    }

    public function execute(Frame $frame): void
    {
        $object = RandomizerBuiltin::receiverRandomizer($frame, 'Random\\Randomizer::getBytes()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'Random\\Randomizer::getBytes() expects at least 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $length = VmMath::parseIntBuiltinArg($frame->calledArgs[1], 'Random\\Randomizer::getBytes', 0, 'length');
        if ($length <= 0) {
            throw new \ValueError('Random\\Randomizer::getBytes(): Argument #1 ($length) must be greater than 0');
        }
        $engine = RandomEngineStorage::engineObject($object);
        $bytes = '';
        while (\strlen($bytes) < $length) {
            $bytes .= \pack('V', RandomEngineStorage::generate($engine));
        }
        $frame->returnVar->string(\substr($bytes, 0, $length));
    }
}

final class RandomizerShuffleArray extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('shuffleArray');
    }

    public function execute(Frame $frame): void
    {
        $object = RandomizerBuiltin::receiverRandomizer($frame, 'Random\\Randomizer::shuffleArray()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'Random\\Randomizer::shuffleArray() expects at least 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $arrayArg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $arrayArg->type) {
            throw new \TypeError(
                'Random\\Randomizer::shuffleArray(): Argument #1 ($array) must be of type array, '
                .EnumCaseSupport::typeNameForVariable($arrayArg).' given'
            );
        }
        $arr = $arrayArg->toArray();
        $keys = \array_keys($arr);
        $count = \count($keys);
        $engine = RandomEngineStorage::engineObject($object);
        for ($i = $count - 1; $i > 0; --$i) {
            $j = RandomEngineStorage::range($engine, 0, $i);
            [$keys[$i], $keys[$j]] = [$keys[$j], $keys[$i]];
        }
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $arr[$key];
        }
        $frame->returnVar->array($out);
    }
}

final class RandomizerShuffleBytes extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('shuffleBytes');
    }

    public function execute(Frame $frame): void
    {
        $object = RandomizerBuiltin::receiverRandomizer($frame, 'Random\\Randomizer::shuffleBytes()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'Random\\Randomizer::shuffleBytes() expects at least 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $str = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'Random\\Randomizer::shuffleBytes',
            0,
            'bytes'
        );
        $len = \strlen($str);
        if ($len <= 1) {
            $frame->returnVar->string($str);

            return;
        }
        $bytes = \str_split($str);
        $engine = RandomEngineStorage::engineObject($object);
        for ($i = $len - 1; $i > 0; --$i) {
            $j = RandomEngineStorage::range($engine, 0, $i);
            [$bytes[$i], $bytes[$j]] = [$bytes[$j], $bytes[$i]];
        }
        $frame->returnVar->string(\implode('', $bytes));
    }
}

final class RandomizerPickArrayKeys extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('pickArrayKeys');
    }

    public function execute(Frame $frame): void
    {
        $object = RandomizerBuiltin::receiverRandomizer($frame, 'Random\\Randomizer::pickArrayKeys()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'Random\\Randomizer::pickArrayKeys() expects at least 2 arguments, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $arrayArg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $arrayArg->type) {
            throw new \TypeError(
                'Random\\Randomizer::pickArrayKeys(): Argument #1 ($array) must be of type array, '
                .EnumCaseSupport::typeNameForVariable($arrayArg).' given'
            );
        }
        $num = VmMath::parseIntBuiltinArg($frame->calledArgs[2], 'Random\\Randomizer::pickArrayKeys', 1, 'num');
        $keys = \array_keys($arrayArg->toArray());
        $count = \count($keys);
        if ($num < 0 || $num > $count) {
            throw new \ValueError(
                'Random\\Randomizer::pickArrayKeys(): Argument #2 ($num) must be between 0 and the number of elements in argument #1 ($array)'
            );
        }
        $engine = RandomEngineStorage::engineObject($object);
        $picked = [];
        $pool = $keys;
        for ($i = 0; $i < $num; ++$i) {
            $idx = RandomEngineStorage::range($engine, 0, \count($pool) - 1);
            $picked[] = $pool[$idx];
            \array_splice($pool, $idx, 1);
        }
        $frame->returnVar->array($picked);
    }
}

final class RandomizerSerialize extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__serialize');
    }

    public function execute(Frame $frame): void
    {
        $object = RandomizerBuiltin::receiverRandomizer($frame, 'Random\\Randomizer::__serialize()');
        if (null === $frame->returnVar) {
            return;
        }

        $engine = new Variable();
        $engine->copyFrom(RandomizerStorage::engine($object)->resolveIndirect());
        $ht = new HashTable();
        $ht->add('engine', $engine);
        $frame->returnVar->array($ht);
    }
}

final class RandomizerUnserialize extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__unserialize');
    }

    public function execute(Frame $frame): void
    {
        $object = RandomizerBuiltin::receiverRandomizer($frame, 'Random\\Randomizer::__unserialize()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'Random\\Randomizer::__unserialize() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $arg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $arg->type) {
            throw new \TypeError(
                'Random\\Randomizer::__unserialize(): Argument #1 ($data) must be of type array'
            );
        }

        $engineVar = null;
        foreach ($arg->toArray()->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $key = $keyVar->resolveIndirect();
            if (Variable::TYPE_STRING === $key->type && 'engine' === $key->toString()) {
                $engineVar = $valueVar;
                break;
            }
        }
        if (null === $engineVar) {
            throw new \TypeError('Random\\Randomizer::__unserialize(): invalid serialized state');
        }
        $resolved = $engineVar->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $resolved->type) {
            throw new \TypeError('Random\\Randomizer::__unserialize(): invalid serialized state');
        }

        RandomizerStorage::setEngine($object, $engineVar);
        $object->constructed = true;
    }
}
