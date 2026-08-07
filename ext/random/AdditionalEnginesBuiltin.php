<?php

declare(strict_types=1);

namespace PHPCompiler\ext\random;

use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/** Random\\Engine\\Secure, Xoshiro256StarStar, PcgOneseq128XslRr64 (#11550). */
final class AdditionalEnginesBuiltin
{
    public const SECURE_LC = 'random\\engine\\secure';
    public const XOSHIRO_LC = 'random\\engine\\xoshiro256starstar';
    public const PCG_LC = 'random\\engine\\pcgoneseq128xslrr64';

    public static function registerClasses(Context $ctx): void
    {
        self::registerSecure($ctx);
        self::registerXoshiro($ctx);
        self::registerPcg($ctx);
    }

    private static function registerSecure(Context $ctx): void
    {
        if (isset($ctx->classes[self::SECURE_LC]->methods['generate'])) {
            return;
        }
        $entry = $ctx->classes[self::SECURE_LC] ?? new ClassEntry('Random\\Engine\\Secure');
        // php-src `final class Secure` (ext/random/random.stub.php; #28387).
        $entry->isFinal = true;
        $entry->interfaces = ['random\\cryptosafeengine'];
        $entry->methods['generate'] = new SecureGenerate();
        $entry->methodVisibility['generate'] = CfgFunc::FLAG_PUBLIC;
        $ctx->classes[self::SECURE_LC] = $entry;
    }

    private static function registerXoshiro(Context $ctx): void
    {
        if (isset($ctx->classes[self::XOSHIRO_LC]->methods['generate'])) {
            return;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = $ctx->classes[self::XOSHIRO_LC] ?? new ClassEntry('Random\\Engine\\Xoshiro256StarStar');
        // php-src `final class Xoshiro256StarStar` (ext/random/random.stub.php; #28387).
        $entry->isFinal = true;
        $entry->interfaces = ['random\\engine'];
        $entry->constructor = new XoshiroConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['generate'] = new XoshiroGenerate();
        $entry->methods['jump'] = new XoshiroJump(false);
        $entry->methods['jumplong'] = new XoshiroJump(true);
        $entry->methods['__serialize'] = new XoshiroSerialize();
        $entry->methods['__unserialize'] = new XoshiroUnserialize();
        $entry->methods['__debuginfo'] = new EngineDebugInfo(self::XOSHIRO_LC);
        foreach (['generate', 'jump', 'jumplong', '__serialize', '__unserialize', '__debuginfo'] as $m) {
            $entry->methodVisibility[$m] = $pub;
        }
        $ctx->classes[self::XOSHIRO_LC] = $entry;
    }

    private static function registerPcg(Context $ctx): void
    {
        if (isset($ctx->classes[self::PCG_LC]->methods['generate'])) {
            return;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = $ctx->classes[self::PCG_LC] ?? new ClassEntry('Random\\Engine\\PcgOneseq128XslRr64');
        // php-src `final class PcgOneseq128XslRr64` (ext/random/random.stub.php; #28387).
        $entry->isFinal = true;
        $entry->interfaces = ['random\\engine'];
        $entry->constructor = new PcgConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['generate'] = new PcgGenerate();
        $entry->methods['jump'] = new PcgJump();
        $entry->methods['__serialize'] = new PcgSerialize();
        $entry->methods['__unserialize'] = new PcgUnserialize();
        $entry->methods['__debuginfo'] = new EngineDebugInfo(self::PCG_LC);
        foreach (['generate', 'jump', '__serialize', '__unserialize', '__debuginfo'] as $m) {
            $entry->methodVisibility[$m] = $pub;
        }
        $ctx->classes[self::PCG_LC] = $entry;
    }

    public static function receiver(Frame $frame, string $lc, string $method): ObjectEntry
    {
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException($method.' called on non-object');
        }
        $object = $receiver->toObject();
        if (strtolower(ltrim($object->class->name, '\\')) !== $lc) {
            throw new \LogicException($method.' called on incompatible object');
        }

        return $object;
    }

    /** @return int|string|null */
    public static function parseOptionalSeedArg(Frame $frame, string $className): int|string|null
    {
        if (!isset($frame->calledArgs[1])) {
            return null;
        }
        $arg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_NULL === $arg->type) {
            return null;
        }
        if (Variable::TYPE_STRING === $arg->type) {
            return $arg->toString();
        }
        if (Variable::TYPE_INTEGER === $arg->type) {
            return $arg->toInt();
        }
        throw new \TypeError($className.'::__construct(): Argument #1 ($seed) must be of type int|string|null, '.EnumCaseSupport::typeNameForVariable($arg).' given');
    }
}

final class SecureGenerate extends VmClassMethod
{
    public function __construct() { parent::__construct('generate'); }
    public function execute(Frame $frame): void
    {
        $object = AdditionalEnginesBuiltin::receiver($frame, AdditionalEnginesBuiltin::SECURE_LC, 'generate');
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(RandomEngineStorage::secure($object)->generate());
        }
    }
}

final class XoshiroConstruct extends VmClassMethod
{
    public function __construct() { parent::__construct('__construct'); }
    public function execute(Frame $frame): void
    {
        $object = AdditionalEnginesBuiltin::receiver($frame, AdditionalEnginesBuiltin::XOSHIRO_LC, '__construct');
        $engine = new Xoshiro256StarStarInstance();
        $seed = AdditionalEnginesBuiltin::parseOptionalSeedArg($frame, 'Random\\Engine\\Xoshiro256StarStar');
        if (null === $seed) { $engine->seedRandom(); }
        elseif (\is_int($seed)) { $engine->seedFromInt($seed); }
        else { $engine->seedFromBytes($seed); }
        RandomEngineStorage::attachXoshiro($object, $engine);
    }
}

final class XoshiroGenerate extends VmClassMethod
{
    public function __construct() { parent::__construct('generate'); }
    public function execute(Frame $frame): void
    {
        $object = AdditionalEnginesBuiltin::receiver($frame, AdditionalEnginesBuiltin::XOSHIRO_LC, 'generate');
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(RandomEngineStorage::xoshiro($object)->generate());
        }
    }
}

final class XoshiroJump extends VmClassMethod
{
    public function __construct(private readonly bool $long) { parent::__construct($long ? 'jumpLong' : 'jump'); }
    public function execute(Frame $frame): void
    {
        $object = AdditionalEnginesBuiltin::receiver($frame, AdditionalEnginesBuiltin::XOSHIRO_LC, $this->long ? 'jumpLong' : 'jump');
        $engine = RandomEngineStorage::xoshiro($object);
        $this->long ? $engine->jumpLong() : $engine->jump();
    }
}

final class XoshiroSerialize extends VmClassMethod
{
    public function __construct() { parent::__construct('__serialize'); }
    public function execute(Frame $frame): void
    {
        $object = AdditionalEnginesBuiltin::receiver($frame, AdditionalEnginesBuiltin::XOSHIRO_LC, '__serialize');
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom(VmJson::import([0 => [], 1 => RandomEngineStorage::xoshiro($object)->exportSerializedState()]));
        }
    }
}

final class XoshiroUnserialize extends VmClassMethod
{
    public function __construct() { parent::__construct('__unserialize'); }
    public function execute(Frame $frame): void
    {
        $object = AdditionalEnginesBuiltin::receiver($frame, AdditionalEnginesBuiltin::XOSHIRO_LC, '__unserialize');
        EngineUnserialize::restore($frame, $object, static fn () => new Xoshiro256StarStarInstance(), RandomEngineStorage::attachXoshiro(...));
    }
}

final class PcgConstruct extends VmClassMethod
{
    public function __construct() { parent::__construct('__construct'); }
    public function execute(Frame $frame): void
    {
        $object = AdditionalEnginesBuiltin::receiver($frame, AdditionalEnginesBuiltin::PCG_LC, '__construct');
        $engine = new PcgOneseq128XslRr64Instance();
        $seed = AdditionalEnginesBuiltin::parseOptionalSeedArg($frame, 'Random\\Engine\\PcgOneseq128XslRr64');
        if (null === $seed) { $engine->seedRandom(); }
        elseif (\is_int($seed)) { $engine->seedFromInt($seed); }
        else { $engine->seedFromBytes($seed); }
        RandomEngineStorage::attachPcg($object, $engine);
    }
}

final class PcgGenerate extends VmClassMethod
{
    public function __construct() { parent::__construct('generate'); }
    public function execute(Frame $frame): void
    {
        $object = AdditionalEnginesBuiltin::receiver($frame, AdditionalEnginesBuiltin::PCG_LC, 'generate');
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(RandomEngineStorage::pcg($object)->generate());
        }
    }
}

final class PcgJump extends VmClassMethod
{
    public function __construct() { parent::__construct('jump'); }
    public function execute(Frame $frame): void
    {
        $object = AdditionalEnginesBuiltin::receiver($frame, AdditionalEnginesBuiltin::PCG_LC, 'jump');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Random\\Engine\\PcgOneseq128XslRr64::jump() expects exactly 1 argument, '.(\count($frame->calledArgs) - 1).' given');
        }
        RandomEngineStorage::pcg($object)->jump(VmMath::parseIntBuiltinArg($frame->calledArgs[1], 'Random\\Engine\\PcgOneseq128XslRr64::jump', 0, 'advance'));
    }
}

final class PcgSerialize extends VmClassMethod
{
    public function __construct() { parent::__construct('__serialize'); }
    public function execute(Frame $frame): void
    {
        $object = AdditionalEnginesBuiltin::receiver($frame, AdditionalEnginesBuiltin::PCG_LC, '__serialize');
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom(VmJson::import([0 => [], 1 => RandomEngineStorage::pcg($object)->exportSerializedState()]));
        }
    }
}

final class PcgUnserialize extends VmClassMethod
{
    public function __construct() { parent::__construct('__unserialize'); }
    public function execute(Frame $frame): void
    {
        $object = AdditionalEnginesBuiltin::receiver($frame, AdditionalEnginesBuiltin::PCG_LC, '__unserialize');
        EngineUnserialize::restore($frame, $object, static fn () => new PcgOneseq128XslRr64Instance(), RandomEngineStorage::attachPcg(...));
    }
}

final class EngineDebugInfo extends VmClassMethod
{
    public function __construct(private readonly string $lc) { parent::__construct('__debugInfo'); }
    public function execute(Frame $frame): void
    {
        AdditionalEnginesBuiltin::receiver($frame, $this->lc, '__debugInfo');
        if (null !== $frame->returnVar) { $frame->returnVar->array([]); }
    }
}

final class EngineUnserialize
{
    /** @param callable(ObjectEntry, object): void $attach */
    public static function restore(Frame $frame, ObjectEntry $object, callable $factory, callable $attach): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('__unserialize() expects exactly 1 argument, '.(\count($frame->calledArgs) - 1).' given');
        }
        $arg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $arg->type) {
            throw new \TypeError('__unserialize(): Argument #1 ($data) must be of type array');
        }
        $stateVar = null;
        foreach ($arg->toArray()->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $key = $keyVar->resolveIndirect();
            if (Variable::TYPE_INTEGER === $key->type && 1 === $key->toInt()) {
                $stateVar = $valueVar->resolveIndirect();
            }
        }
        if (null === $stateVar || Variable::TYPE_ARRAY !== $stateVar->type) {
            throw new \TypeError('__unserialize(): invalid serialized state');
        }
        $payload = VmJson::export($stateVar);
        $engine = $factory();
        $attach($object, $engine);
        if (!\is_array($payload)) {
            throw new \TypeError('__unserialize(): invalid serialized state');
        }
        $engine->restoreFromSerializedState($payload);
        $object->constructed = true;
    }
}
