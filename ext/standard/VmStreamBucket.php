<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Stream filter bucket brigade registry — php-src ext/standard/streams.c (#7089, #6053).
 *
 * Brigades and bucket handles are tagged integers in {@see Variable}; no C in phpc_stream.c.
 */
final class VmStreamBucket
{
    private const STDCLASS_LC = 'stdclass';

    public const PROP_BUCKET = 'bucket';

    public const PROP_DATA = 'data';

    public const PROP_DATALEN = 'datalen';

    /** @var array<int, list<int>> */
    private static array $brigades = [];

    /** @var array<int, string> */
    private static array $bucketData = [];

    private static int $nextBrigadeId = 1;

    private static int $nextBucketId = 1;

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
        if (!$entry->hasProperty(self::PROP_BUCKET)) {
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
        if ($entry->hasProperty(self::PROP_DATALEN)) {
            $entry->getProperty(self::PROP_DATALEN)->int(\strlen($data));
        }
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
        $class = $ctx->classes[self::STDCLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('stdClass is not registered in this compiler build');
        }
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $obj = new Variable(Variable::TYPE_OBJECT);
        $obj->object($entry);

        self::bucketHandle($entry->allocateProperty(self::PROP_BUCKET), $bucketId);
        $entry->allocateProperty(self::PROP_DATA)->string($data);
        $entry->allocateProperty(self::PROP_DATALEN)->int(\strlen($data));

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
