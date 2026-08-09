<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mongodb;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmString;

/** MongoDB\Driver VM classes (PECL mongodb; #6575, #27875). */
final class VmMongodb
{
    public const MANAGER_LC = 'mongodb\\driver\\manager';
    public const BULKWRITE_LC = 'mongodb\\driver\\bulkwrite';
    public const QUERY_LC = 'mongodb\\driver\\query';
    public const CURSOR_LC = 'mongodb\\driver\\cursor';
    public const RUNTIME_EX_LC = 'mongodb\\driver\\exception\\runtimeexception';
    public const INVALID_ARG_EX_LC = 'mongodb\\driver\\exception\\invalidargumentexception';

    /** @var array<int, ManagerState> */
    private static array $managers = [];

    public static function registerClasses(Context $ctx): void
    {
        require_once __DIR__.'/ManagerState.php';
        require_once __DIR__.'/MongodbClassMethod.php';
        require_once __DIR__.'/ManagerConstruct.php';
        require_once __DIR__.'/ManagerExecuteBulkWrite.php';
        require_once __DIR__.'/ManagerExecuteQuery.php';
        require_once __DIR__.'/BulkWriteConstruct.php';
        require_once __DIR__.'/QueryConstruct.php';
        self::registerException($ctx, self::RUNTIME_EX_LC, 'MongoDB\\Driver\\Exception\\RuntimeException', 'runtimeexception');
        self::registerException($ctx, self::INVALID_ARG_EX_LC, 'MongoDB\\Driver\\Exception\\InvalidArgumentException', 'invalidargumentexception');
        self::registerManager($ctx);
        self::registerBulkWrite($ctx);
        self::registerQuery($ctx);
        self::registerCursor($ctx);
        require_once __DIR__.'/VmMongodbTypes.php';
        VmMongodbTypes::register($ctx);
    }

    private static function registerException(Context $ctx, string $lc, string $name, string $parentLc): void
    {
        if (isset($ctx->classes[$lc])) {
            return;
        }
        $entry = new ClassEntry($name);
        $entry->isInternal = true;
        if (isset($ctx->classes[$parentLc])) {
            $entry->parentLc = $parentLc;
        }
        $ctx->classes[$lc] = $entry;
    }

    private static function registerManager(Context $ctx): void
    {
        if (isset($ctx->classes[self::MANAGER_LC])) {
            return;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('MongoDB\\Driver\\Manager');
        $entry->isInternal = true;
        $entry->isFinal = true;
        $ctor = new ManagerConstruct();
        $entry->constructor = $ctor;
        $entry->methods['__construct'] = $ctor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['executebulkwrite'] = new ManagerExecuteBulkWrite();
        $entry->methodVisibility['executebulkwrite'] = $pub;
        $entry->methodNames['executebulkwrite'] = 'executeBulkWrite';
        $entry->methods['executequery'] = new ManagerExecuteQuery();
        $entry->methodVisibility['executequery'] = $pub;
        $entry->methodNames['executequery'] = 'executeQuery';
        $ctx->classes[self::MANAGER_LC] = $entry;
    }

    private static function registerBulkWrite(Context $ctx): void
    {
        if (isset($ctx->classes[self::BULKWRITE_LC])) {
            return;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('MongoDB\\Driver\\BulkWrite');
        $entry->isInternal = true;
        $entry->isFinal = true;
        $ctor = new BulkWriteConstruct();
        $entry->constructor = $ctor;
        $entry->methods['__construct'] = $ctor;
        $entry->methodVisibility['__construct'] = $pub;
        $ctx->classes[self::BULKWRITE_LC] = $entry;
    }

    private static function registerQuery(Context $ctx): void
    {
        if (isset($ctx->classes[self::QUERY_LC])) {
            return;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('MongoDB\\Driver\\Query');
        $entry->isInternal = true;
        $entry->isFinal = true;
        $ctor = new QueryConstruct();
        $entry->constructor = $ctor;
        $entry->methods['__construct'] = $ctor;
        $entry->methodVisibility['__construct'] = $pub;
        $ctx->classes[self::QUERY_LC] = $entry;
    }

    private static function registerCursor(Context $ctx): void
    {
        if (isset($ctx->classes[self::CURSOR_LC])) {
            return;
        }
        $entry = new ClassEntry('MongoDB\\Driver\\Cursor');
        $entry->isInternal = true;
        $entry->isFinal = true;
        $ctx->classes[self::CURSOR_LC] = $entry;
    }

    public static function initManager(ObjectEntry $entry, string $uri, ?array $uriOptions, ?array $driverOptions): void
    {
        $state = new ManagerState();
        $state->uri = $uri;
        $state->uriOptions = $uriOptions;
        $state->driverOptions = $driverOptions;
        self::$managers[$entry->id] = $state;
        $entry->constructed = true;
    }

    public static function initSimple(ObjectEntry $entry): void
    {
        $entry->constructed = true;
    }

    public static function requireReceiver(Variable $var, string $label, string $classLc): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf('%s must be called on an object, %s given', $label, self::typeLabel($var)));
        }
        $object = $var->toObject();
        if ($classLc !== strtolower($object->class->name)) {
            throw new \TypeError(\sprintf('%s must be called on %s, %s given', $label, $classLc, $object->class->name));
        }
        if (!$object->constructed) {
            throw new \TypeError(\sprintf('%s must be called on initialized %s', $label, $object->class->name));
        }

        return $object;
    }

    public static function coerceUri(Variable $var, string $label): string
    {
        return VmString::coerceStringBuiltinArg($var, $label, 0, 'uri');
    }

    public static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'unknown',
        };
    }
}
