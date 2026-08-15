<?php

declare(strict_types=1);

namespace PHPCompiler\ext\random;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\VmArray;
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

/** Per-object engine state for Random\Engine\* (#13191, #11550). */
final class RandomEngineStorage
{
    /** @var array<int, Mt19937Instance> */
    private static array $mt19937 = [];

    /** @var array<int, SecureInstance> */
    private static array $secure = [];

    /** @var array<int, Xoshiro256StarStarInstance> */
    private static array $xoshiro = [];

    /** @var array<int, PcgOneseq128XslRr64Instance> */
    private static array $pcg = [];

    public static function attachMt19937(ObjectEntry $object, Mt19937Instance $engine): void
    {
        self::$mt19937[$object->id] = $engine;
    }

    public static function attachSecure(ObjectEntry $object, SecureInstance $engine): void
    {
        self::$secure[$object->id] = $engine;
    }

    public static function attachXoshiro(ObjectEntry $object, Xoshiro256StarStarInstance $engine): void
    {
        self::$xoshiro[$object->id] = $engine;
    }

    public static function attachPcg(ObjectEntry $object, PcgOneseq128XslRr64Instance $engine): void
    {
        self::$pcg[$object->id] = $engine;
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

    public static function secure(ObjectEntry $object): SecureInstance
    {
        return self::$secure[$object->id] ??= new SecureInstance();
    }

    public static function xoshiro(ObjectEntry $object): Xoshiro256StarStarInstance
    {
        $engine = self::$xoshiro[$object->id] ?? null;
        if (null === $engine) {
            throw new \LogicException('Random engine state missing');
        }

        return $engine;
    }

    public static function tryXoshiro(ObjectEntry $object): ?Xoshiro256StarStarInstance
    {
        return self::$xoshiro[$object->id] ?? null;
    }

    public static function pcg(ObjectEntry $object): PcgOneseq128XslRr64Instance
    {
        $engine = self::$pcg[$object->id] ?? null;
        if (null === $engine) {
            throw new \LogicException('Random engine state missing');
        }

        return $engine;
    }

    public static function engineObject(ObjectEntry $randomizer): ObjectEntry
    {
        $engineVar = RandomizerStorage::engine($randomizer);
        if (Variable::TYPE_OBJECT !== $engineVar->type) {
            throw new \Random\RandomError('Engine must be an object');
        }

        return $engineVar->toObject();
    }

    public static function generate(ObjectEntry $engineObject): string
    {
        return match (strtolower(ltrim($engineObject->class->name, '\\'))) {
            'random\\engine\\mt19937' => self::mt19937($engineObject)->generate(),
            'random\\engine\\secure', 'random\\engine\\xoshiro256starstar', 'random\\engine\\pcgoneseq128xslrr64' => self::generateBytes($engineObject),
            default => throw new \LogicException('Unsupported random engine: '.$engineObject->class->name),
        };
    }

    public static function generateBytes(ObjectEntry $engineObject): string
    {
        return match (strtolower(ltrim($engineObject->class->name, '\\'))) {
            'random\\engine\\secure' => self::secure($engineObject)->generate(),
            'random\\engine\\xoshiro256starstar' => self::xoshiro($engineObject)->generate(),
            'random\\engine\\pcgoneseq128xslrr64' => self::pcg($engineObject)->generate(),
            default => throw new \LogicException('Unsupported random engine: '.$engineObject->class->name),
        };
    }

    public static function generateUInt32(ObjectEntry $engineObject): int
    {
        return self::bytesToUInt32(self::generate($engineObject));
    }

    public static function generateUInt64(ObjectEntry $engineObject): int
    {
        return self::bytesToUInt64(self::generate($engineObject));
    }

    /** php-src randomizer.c Randomizer::nextInt() — generate then >> 1. */
    public static function generateForNextInt(ObjectEntry $engineObject): int
    {
        return match (strtolower(ltrim($engineObject->class->name, '\\'))) {
            'random\\engine\\mt19937' => self::mt19937($engineObject)->generateRaw() >> 1,
            default => self::generateRandomU64($engineObject)->shiftRight(1)->toInt(),
        };
    }

    /** php-src random.c php_random_range64 — unbiased uint64 in [0, $umax]. */
    public static function range64(ObjectEntry $engineObject, int $umax): int
    {
        if ($umax < 0) {
            throw new \LogicException('range64 umax must be non-negative');
        }

        $result = self::generateRandomU64($engineObject);
        // php-src: umax == UINT64_MAX → return raw. Not representable as signed PHP int.

        $umaxPlusOne = $umax + 1;
        // Powers of two are unbiased: return result & umax (php-src after umax++).
        // umax may exceed 32 bits (e.g. 2^52-1 for getFloat [0,1) γ-section) — #28526.
        if (($umaxPlusOne & $umax) === 0) {
            return RandomU64::and($result, RandomU64::fromUint64($umax))->toInt();
        }

        // limit = UINT64_MAX - (UINT64_MAX % umaxPlusOne) - 1
        $u64max = RandomU64::fromParts(0xFFFFFFFF, 0xFFFFFFFF);
        $remainder = RandomU64::modSmall($u64max, $umaxPlusOne);
        $limitNot = ~($remainder + 1);
        $limit = RandomU64::fromParts(($limitNot >> 32) & 0xFFFFFFFF, $limitNot & 0xFFFFFFFF);
        $attempts = 0;
        while (RandomU64::compare($result, $limit) > 0) {
            if (++$attempts > 50) {
                throw new \Random\BrokenRandomEngineError(
                    'Failed to generate an acceptable random number in 50 attempts'
                );
            }
            $result = self::generateRandomU64($engineObject);
        }

        return RandomU64::modSmall($result, $umaxPlusOne);
    }

    /** php-src randomizer.c Randomizer::nextFloat() — [0, 1) with 53-bit precision. */
    public static function generateNextFloat(ObjectEntry $engineObject): float
    {
        return self::generateRandomU64($engineObject)->upper53UnitFloat();
    }

    public static function generateRandomU64(ObjectEntry $engineObject): RandomU64
    {
        $bytes = '';
        while (\strlen($bytes) < 8) {
            $bytes .= self::generate($engineObject);
        }
        $parts = \unpack('V2', \substr($bytes, 0, 8));

        return RandomU64::fromParts($parts[2], $parts[1]);
    }

    private static function bytesToUInt32(string $bytes): int
    {
        return self::bytesToUInt64($bytes) & 0xFFFFFFFF;
    }

    private static function bytesToUInt64(string $bytes): int
    {
        $parts = \unpack('V2', $bytes);

        return (int) (($parts[2] ?? 0) << 32 | ($parts[1] ?? 0));
    }

    public static function range(ObjectEntry $engineObject, int $min, int $max): int
    {
        return match (strtolower(ltrim($engineObject->class->name, '\\'))) {
            'random\\engine\\mt19937' => self::mt19937($engineObject)->range($min, $max),
            'random\\engine\\secure' => self::secure($engineObject)->range($min, $max),
            'random\\engine\\xoshiro256starstar', 'random\\engine\\pcgoneseq128xslrr64' => self::rangeFromGenerate($engineObject, $min, $max),
            default => throw new \LogicException('Unsupported random engine: '.$engineObject->class->name),
        };
    }

    private static function rangeFromGenerate(ObjectEntry $engineObject, int $min, int $max): int
    {
        if ($max < $min) {
            throw new \ValueError('Random\\Randomizer::getInt(): Argument #2 ($max) must be greater than or equal to argument #1 ($min)');
        }
        if ($min === $max) {
            return $min;
        }
        $umax = $max - $min;
        if ($umax > 0xFFFFFFFF) {
            return $min + self::rangeFromGenerate64($engineObject, $umax);
        }

        return $min + self::rangeFromGenerate32($engineObject, $umax);
    }

    private static function rangeFromGenerate32(ObjectEntry $engineObject, int $umax): int
    {
        if (0xFFFFFFFF === $umax) {
            return self::generateUInt32($engineObject);
        }
        ++$umax;
        if (($umax & ($umax - 1)) === 0) {
            return self::generateUInt32($engineObject) & ($umax - 1);
        }
        $limit = 0xFFFFFFFF - (int) (0xFFFFFFFF % $umax) - 1;
        $result = self::generateUInt32($engineObject);
        while ($result > $limit) {
            $result = self::generateUInt32($engineObject);
        }

        return $result % $umax;
    }

    private static function rangeFromGenerate64(ObjectEntry $engineObject, int $umax): int
    {
        ++$umax;
        $limit = \PHP_INT_MAX - (int) (\PHP_INT_MAX % $umax) - 1;
        $result = self::generateUInt64($engineObject);
        while ($result > $limit) {
            $result = self::generateUInt64($engineObject);
        }

        return $result % $umax;
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
        AdditionalEnginesBuiltin::registerClasses($ctx);
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
        // php-src `final class Mt19937` (ext/random/random.stub.php; #28387).
        $entry->isFinal = true;

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
        // php-src `final class Randomizer` (ext/random/random.stub.php; #28387).
        $entry->isFinal = true;

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

        if (CompilerVersion::supportsRandomIntervalBoundary()) {
            foreach ([
                'nextfloat' => RandomizerNextFloat::class,
                'getfloat' => RandomizerGetFloat::class,
                'getbytesfromstring' => RandomizerGetBytesFromString::class,
            ] as $lc => $class) {
                $entry->methods[$lc] = new $class();
                $entry->methodVisibility[$lc] = $pub;
            }
        }

        $ctx->classes[self::RANDOMIZER_LC] = $entry;
    }

    private static function mt19937IsComplete(ClassEntry $entry): bool
    {
        return isset($entry->methods['generate'], $entry->methods['__construct']);
    }

    private static function randomizerIsComplete(ClassEntry $entry): bool
    {
        $base = isset(
            $entry->methods['getint'],
            $entry->methods['nextint'],
            $entry->methods['__construct'],
            $entry->methods['__serialize'],
            $entry->methods['__unserialize']
        );
        if (!$base) {
            return false;
        }
        if (CompilerVersion::supportsRandomIntervalBoundary()) {
            return isset(
                $entry->methods['getfloat'],
                $entry->methods['nextfloat'],
                $entry->methods['getbytesfromstring']
            );
        }

        return true;
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
        // php-src engine_mt19937.c generate() — ZEND_PARSE_PARAMETERS exactly 0 (#31096).
        $this->requireExactUserArgCount($frame, 'Random\\Engine\\Mt19937::generate', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(RandomEngineStorage::mt19937($object)->generate());
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

    /**
     * php-src Randomizer default engine is Random\Engine\Secure (CSPRNG),
     * not Mt19937 — see ext/random/randomizer.c php_random_randomizer_construct (#23163).
     */
    private static function createDefaultEngineObject(Frame $frame): ObjectEntry
    {
        $engineObject = new ObjectEntry($frame->vmContext->classes[AdditionalEnginesBuiltin::SECURE_LC]);
        $engineObject->constructed = true;
        RandomEngineStorage::attachSecure($engineObject, new SecureInstance());

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
        $frame->returnVar->int(RandomEngineStorage::generateForNextInt($engine));
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
        // php-src randomizer.c zim_Random_Randomizer_getInt — ZEND_PARSE_PARAMETERS exactly 2 (#31092).
        $this->requireExactUserArgCount($frame, 'Random\\Randomizer::getInt', 2);
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
        // php-src randomizer.c zim_Random_Randomizer_getBytes — ZEND_PARSE_PARAMETERS exactly 1 (#31092).
        $this->requireExactUserArgCount($frame, 'Random\\Randomizer::getBytes', 1);
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
            $bytes .= RandomEngineStorage::generate($engine);
        }
        $frame->returnVar->string(\substr($bytes, 0, $length));
    }
}

/**
 * PHP 8.3+ Randomizer::getBytesFromString — php-src ext/random/randomizer.c (#19572).
 */
final class RandomizerGetBytesFromString extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getBytesFromString');
    }

    public function execute(Frame $frame): void
    {
        $object = RandomizerBuiltin::receiverRandomizer($frame, 'Random\\Randomizer::getBytesFromString()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'Random\\Randomizer::getBytesFromString() expects at least 2 arguments, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $source = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'Random\\Randomizer::getBytesFromString',
            0,
            'string'
        );
        $length = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[2],
            'Random\\Randomizer::getBytesFromString',
            1,
            'length'
        );
        $engine = RandomEngineStorage::engineObject($object);
        $frame->returnVar->string(RandomizerGetBytesFromStringAlgo::compute(
            $source,
            $length,
            static fn (int $min, int $max): int => RandomEngineStorage::range($engine, $min, $max),
            static fn (): string => RandomEngineStorage::generate($engine)
        ));
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
        // php-src randomizer.c zim_Random_Randomizer_shuffleArray — ZEND_PARSE_PARAMETERS exactly 1 (#31092).
        $this->requireExactUserArgCount($frame, 'Random\\Randomizer::shuffleArray', 1);
        // php-src randomizer.c: zend_array_dup then shuffle; return copy (input unchanged).
        $src = VmArray::requireArrayParam($frame->calledArgs[1], 'Random\\Randomizer::shuffleArray', 1, 'array');
        $ht = $src->duplicate();
        $engine = RandomEngineStorage::engineObject($object);
        VmArray::shufflePackedWithPicker(
            $ht,
            static fn (int $upper): int => RandomEngineStorage::range($engine, 0, $upper - 1)
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->array($ht);
        }
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
        // php-src randomizer.c zim_Random_Randomizer_pickArrayKeys — ZEND_PARSE_PARAMETERS exactly 2 (#31092).
        $this->requireExactUserArgCount($frame, 'Random\\Randomizer::pickArrayKeys', 2);
        if (null === $frame->returnVar) {
            return;
        }
        $ht = VmArray::requireArrayParam($frame->calledArgs[1], 'Random\\Randomizer::pickArrayKeys', 1, 'array');
        $num = VmMath::parseIntBuiltinArg($frame->calledArgs[2], 'Random\\Randomizer::pickArrayKeys', 1, 'num');
        $numAvail = $ht->getNumElements();
        if (0 === $numAvail) {
            throw new \ValueError('Random\\Randomizer::pickArrayKeys(): Argument #1 ($array) cannot be empty');
        }
        if ($num < 1 || $num > $numAvail) {
            throw new \ValueError(
                'Random\\Randomizer::pickArrayKeys(): Argument #2 ($num) must be between 1 and the number of elements in argument #1 ($array)'
            );
        }
        $engine = RandomEngineStorage::engineObject($object);
        $picked = VmArray::pickKeysWithPicker(
            $ht,
            $num,
            static fn (int $min, int $max): int => RandomEngineStorage::range($engine, $min, $max),
            true
        );
        $frame->returnVar->copyFrom($picked);
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

final class RandomizerNextFloat extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('nextFloat');
    }

    public function execute(Frame $frame): void
    {
        $object = RandomizerBuiltin::receiverRandomizer($frame, 'Random\\Randomizer::nextFloat()');
        if (null === $frame->returnVar) {
            return;
        }
        $engine = RandomEngineStorage::engineObject($object);
        $frame->returnVar->float(RandomEngineStorage::generateNextFloat($engine));
    }
}

final class RandomizerGetFloat extends VmClassMethod
{
    private const INTERVAL_BOUNDARY_LC = 'random\\intervalboundary';

    public function __construct()
    {
        parent::__construct('getFloat');
    }

    public function execute(Frame $frame): void
    {
        $object = RandomizerBuiltin::receiverRandomizer($frame, 'Random\\Randomizer::getFloat()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'Random\\Randomizer::getFloat() expects at least 2 arguments, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $min = VmMath::parseDoubleBuiltinArg($frame->calledArgs[1], 'Random\\Randomizer::getFloat', 0, 'min');
        $max = VmMath::parseDoubleBuiltinArg($frame->calledArgs[2], 'Random\\Randomizer::getFloat', 1, 'max');
        if (!\is_finite($min)) {
            throw new \ValueError('Random\\Randomizer::getFloat(): Argument #1 ($min) must be finite');
        }
        if (!\is_finite($max)) {
            throw new \ValueError('Random\\Randomizer::getFloat(): Argument #2 ($max) must be finite');
        }

        $bounds = 'ClosedOpen';
        if (isset($frame->calledArgs[3])) {
            $bounds = self::parseIntervalBoundary($frame->calledArgs[3]);
        }

        $engine = RandomEngineStorage::engineObject($object);
        $result = match ($bounds) {
            'ClosedOpen' => self::closedOpen($engine, $min, $max),
            'ClosedClosed' => self::closedClosed($engine, $min, $max),
            'OpenClosed' => self::openClosed($engine, $min, $max),
            'OpenOpen' => self::openOpen($engine, $min, $max),
            default => throw new \LogicException('Unknown IntervalBoundary case'),
        };
        $frame->returnVar->float($result);
    }

    private static function closedOpen(ObjectEntry $engine, float $min, float $max): float
    {
        if ($max <= $min) {
            throw new \ValueError('Random\\Randomizer::getFloat(): Argument #2 ($max) must be greater than argument #1 ($min)');
        }

        return GammaSection::closedOpen($engine, $min, $max);
    }

    private static function closedClosed(ObjectEntry $engine, float $min, float $max): float
    {
        if ($max < $min) {
            throw new \ValueError('Random\\Randomizer::getFloat(): Argument #2 ($max) must be greater than or equal to argument #1 ($min)');
        }

        return GammaSection::closedClosed($engine, $min, $max);
    }

    private static function openClosed(ObjectEntry $engine, float $min, float $max): float
    {
        if ($max <= $min) {
            throw new \ValueError('Random\\Randomizer::getFloat(): Argument #2 ($max) must be greater than argument #1 ($min)');
        }

        return GammaSection::openClosed($engine, $min, $max);
    }

    private static function openOpen(ObjectEntry $engine, float $min, float $max): float
    {
        if ($max <= $min) {
            throw new \ValueError('Random\\Randomizer::getFloat(): Argument #2 ($max) must be greater than argument #1 ($min)');
        }
        $result = GammaSection::openOpen($engine, $min, $max);
        if (\is_nan($result)) {
            throw new \ValueError(
                'The given interval is empty, there are no floats between argument #1 ($min) and argument #2 ($max)'
            );
        }

        return $result;
    }

    private static function parseIntervalBoundary(Variable $var): string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type || !EnumCaseSupport::isEnumCase($var->toObject())) {
            throw new \TypeError(
                'Random\\Randomizer::getFloat(): Argument #3 ($boundary) must be of type Random\\IntervalBoundary, '
                .EnumCaseSupport::typeNameForVariable($var).' given'
            );
        }
        $object = $var->toObject();
        if (strtolower(ltrim($object->class->name, '\\')) !== self::INTERVAL_BOUNDARY_LC) {
            throw new \TypeError(
                'Random\\Randomizer::getFloat(): Argument #3 ($boundary) must be of type Random\\IntervalBoundary, '
                .$object->class->name.' given'
            );
        }

        return EnumCaseSupport::enumCaseNameForVariable($var);
    }
}
