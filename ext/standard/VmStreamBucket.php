<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Stream filter bucket brigade registry — php-src ext/standard/user_filters.c (#7089, #6053, #26923).
 *
 * Brigades and bucket handles are tagged integers in {@see Variable}; no C in phpc_stream.c.
 * PHP 8.4+ materializes final {@see StreamBucket}; ≤8.3 keeps stdClass (#10325).
 */
final class VmStreamBucket
{
    private const STDCLASS_LC = 'stdclass';

    public const CLASS_NAME = 'StreamBucket';

    public const CLASS_LC = 'streambucket';

    public const PROP_BUCKET = 'bucket';

    public const PROP_DATA = 'data';

    public const PROP_DATALEN = 'datalen';

    public const PROP_DATA_LENGTH = 'dataLength';

    /** @var array<int, list<int>> */
    private static array $brigades = [];

    /** @var array<int, string> */
    private static array $bucketData = [];

    private static int $nextBrigadeId = 1;

    private static int $nextBucketId = 1;

    /** Register final StreamBucket under PROFILE≥8.4 (php-src user_filters.stub.php; #26923). */
    public static function registerClass(Context $ctx): void
    {
        if (!CompilerVersion::supportsStreamBucketClass()) {
            return;
        }
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry(self::CLASS_NAME);
        $entry->isFinal = true;
        $entry->isInternal = true;
        $entry->allowsDynamicProperties = false;

        $pub = CfgFunc::FLAG_PUBLIC;
        $nullProto = new Variable(Variable::TYPE_NULL);
        $strProto = new Variable(Variable::TYPE_UNDEFINED);
        $strProto->declaredTypeLabel = 'string';
        $intProto = new Variable(Variable::TYPE_UNDEFINED);
        $intProto->declaredTypeLabel = 'int';

        $entry->properties[] = new ClassProperty(
            self::PROP_BUCKET,
            $nullProto,
            new Variable(Variable::TYPE_NULL),
            false,
            $pub,
            self::CLASS_LC
        );
        $entry->properties[] = new ClassProperty(
            self::PROP_DATA,
            null,
            $strProto,
            false,
            $pub,
            self::CLASS_LC
        );
        $entry->properties[] = new ClassProperty(
            self::PROP_DATALEN,
            null,
            $intProto,
            false,
            $pub,
            self::CLASS_LC
        );
        $entry->properties[] = new ClassProperty(
            self::PROP_DATA_LENGTH,
            null,
            clone $intProto,
            false,
            $pub,
            self::CLASS_LC
        );

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function allocateBrigade(): int
    {
        $id = self::$nextBrigadeId++;
        self::$brigades[$id] = [];

        return $id;
    }

    public static function allocateBucket(string $data): int
    {
        $id = self::$nextBucketId++;
        self::$bucketData[$id] = $data;

        return $id;
    }

    public static function isValidBrigade(int $id): bool
    {
        return isset(self::$brigades[$id]);
    }

    public static function isValidBucket(int $id): bool
    {
        return isset(self::$bucketData[$id]);
    }

    public static function brigadeHandle(Variable $target, int $id): void
    {
        $target->brigadeHandle($id);
    }

    public static function bucketHandle(Variable $target, int $id): void
    {
        $target->bucketHandle($id);
    }

    public static function requireBrigadeResource(Variable $v, string $functionName, int $argNum = 1): int
    {
        $v = $v->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($v)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($brigade) must be of type resource, %s given',
                $functionName,
                $argNum,
                EnumCaseSupport::typeNameForVariable($v)
            ));
        }
        if (!$v->isBrigadeResource() || !self::isValidBrigade($v->toInt())) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($brigade) must be of type resource, %s given',
                $functionName,
                $argNum,
                VmStreamArg::debugTypeName($v)
            ));
        }

        return $v->toInt();
    }

    public static function requireStreamBucketObject(
        Variable $v,
        string $functionName,
        int $argNum = 2,
        string $paramName = 'bucket'
    ): ObjectEntry {
        $v = $v->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($v)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type object, %s given',
                $functionName,
                $argNum,
                $paramName,
                EnumCaseSupport::typeNameForVariable($v)
            ));
        }
        if (Variable::TYPE_OBJECT !== $v->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type object, %s given',
                $functionName,
                $argNum,
                $paramName,
                VmStreamArg::debugTypeName($v)
            ));
        }
        $entry = $v->toObject();
        if (CompilerVersion::supportsStreamBucketClass()) {
            if (self::CLASS_LC !== strtolower($entry->class->name)) {
                throw new \TypeError(\sprintf(
                    '%s(): Argument #%d ($%s) must be of type %s, %s given',
                    $functionName,
                    $argNum,
                    $paramName,
                    self::CLASS_NAME,
                    $entry->class->name
                ));
            }
        } elseif (!$entry->hasProperty(self::PROP_BUCKET)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be an object that has a "bucket" property',
                $functionName,
                $argNum,
                $paramName
            ));
        }
        try {
            self::bucketIdFromObject($entry);
        } catch (\LogicException) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be an object that has a "bucket" property',
                $functionName,
                $argNum,
                $paramName
            ));
        }

        return $entry;
    }

    public static function requireBufferString(Variable $v, string $functionName, int $argNum = 2): string
    {
        $v = $v->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($v)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($buffer) must be of type string, %s given',
                $functionName,
                $argNum,
                EnumCaseSupport::typeNameForVariable($v)
            ));
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($buffer) must be of type string, %s given',
                $functionName,
                $argNum,
                VmStreamArg::debugTypeName($v)
            ));
        }

        return $v->toString();
    }

    public static function newBucketObject(Context $ctx, int $streamHandle, string $data): Variable
    {
        $bucketId = self::allocateBucket($data);

        return self::materializeStdClassBucket($ctx, $bucketId, $data);
    }

    public static function bucketIdFromObject(ObjectEntry $entry): int
    {
        $bucketVar = $entry->getProperty(self::PROP_BUCKET)->resolveIndirect();
        if (!$bucketVar->isBucketResource() || !self::isValidBucket($bucketVar->toInt())) {
            throw new \LogicException('StreamBucket::$bucket is not a valid bucket resource');
        }

        return $bucketVar->toInt();
    }

    public static function bucketData(int $bucketId): string
    {
        return self::$bucketData[$bucketId];
    }

    public static function setBucketData(int $bucketId, string $data): void
    {
        self::$bucketData[$bucketId] = $data;
    }

    public static function makeWriteable(Context $ctx, int $brigadeId): ?Variable
    {
        if ([] === self::$brigades[$brigadeId]) {
            return null;
        }
        $bucketId = array_shift(self::$brigades[$brigadeId]);

        return self::bucketObjectForFrame($ctx, $bucketId);
    }

    public static function append(int $brigadeId, ObjectEntry $bucketObj): void
    {
        $bucketId = self::bucketIdFromObject($bucketObj);
        self::syncBucketDataFromObject($bucketObj, $bucketId);
        self::$brigades[$brigadeId][] = $bucketId;
    }

    public static function prepend(int $brigadeId, ObjectEntry $bucketObj): void
    {
        $bucketId = self::bucketIdFromObject($bucketObj);
        self::syncBucketDataFromObject($bucketObj, $bucketId);
        array_unshift(self::$brigades[$brigadeId], $bucketId);
    }

    private static function syncBucketDataFromObject(ObjectEntry $entry, int $bucketId): void
    {
        if (!$entry->hasProperty(self::PROP_DATA)) {
            return;
        }
        $data = $entry->getProperty(self::PROP_DATA)->resolveIndirect()->toString();
        self::$bucketData[$bucketId] = $data;
        // Zend attach copies buf from ->data only; leave datalen/dataLength as user wrote them.
    }

    public static function bucketObjectForFrame(Context $ctx, int $bucketId, ?string $data = null): Variable
    {
        if (!self::isValidBucket($bucketId)) {
            throw new \LogicException('Invalid stream bucket id '.$bucketId);
        }
        $data ??= self::$bucketData[$bucketId];

        return self::materializeStdClassBucket($ctx, $bucketId, $data);
    }

    private static function materializeStdClassBucket(Context $ctx, int $bucketId, string $data): Variable
    {
        $useStreamBucket = CompilerVersion::supportsStreamBucketClass();
        $classLc = $useStreamBucket ? self::CLASS_LC : self::STDCLASS_LC;
        $class = $ctx->classes[$classLc] ?? null;
        if (null === $class) {
            throw new \LogicException(
                ($useStreamBucket ? self::CLASS_NAME : 'stdClass')
                .' is not registered in this compiler build'
            );
        }
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $obj = new Variable(Variable::TYPE_OBJECT);
        $obj->object($entry);

        $len = \strlen($data);
        if ($entry->hasProperty(self::PROP_BUCKET)) {
            self::bucketHandle($entry->getProperty(self::PROP_BUCKET), $bucketId);
        } else {
            self::bucketHandle($entry->allocateProperty(self::PROP_BUCKET), $bucketId);
        }
        if ($entry->hasProperty(self::PROP_DATA)) {
            $entry->getProperty(self::PROP_DATA)->string($data);
        } else {
            $entry->allocateProperty(self::PROP_DATA)->string($data);
        }
        if ($entry->hasProperty(self::PROP_DATALEN)) {
            $entry->getProperty(self::PROP_DATALEN)->int($len);
        } else {
            $entry->allocateProperty(self::PROP_DATALEN)->int($len);
        }
        if ($useStreamBucket) {
            if ($entry->hasProperty(self::PROP_DATA_LENGTH)) {
                $entry->getProperty(self::PROP_DATA_LENGTH)->int($len);
            } else {
                $entry->allocateProperty(self::PROP_DATA_LENGTH)->int($len);
            }
        }

        return $obj;
    }

    public static function getResourceType(int $handle, bool $isBrigade): ?string
    {
        if ($isBrigade) {
            return self::isValidBrigade($handle) ? 'Unknown' : null;
        }

        return self::isValidBucket($handle) ? 'Unknown' : null;
    }
}
