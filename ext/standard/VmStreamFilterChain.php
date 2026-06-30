<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM;
use PHPCompiler\VM\ClassValidator;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Per-stream built-in filter chains — php-src ext/standard/streams.c (#3283).
 *
 * PHP-in-PHP: no logic in runtime/*.c; apply on VmFs read/write paths.
 */
final class VmStreamFilterChain
{
    public const READ = 1;

    public const WRITE = 2;

    /** @var array<string, true> */
    private const BUILTIN_FILTERS = [
        'string.rot13' => true,
        'string.toupper' => true,
        'string.tolower' => true,
        'convert.base64-encode' => true,
        'convert.base64-decode' => true,
        'convert.quoted-printable-decode' => true,
        'convert.quoted-printable-encode' => true,
    ];

    /** @var array<int, array{stream: int, name: string, active: bool, user: bool, instance: ?ObjectEntry, oncreate: bool}> */
    private static array $filters = [];

    /** @var array<int, list<int>> */
    private static array $readChains = [];

    /** @var array<int, list<int>> */
    private static array $writeChains = [];

    /** @var array<int, Context> VM context captured when user filters attach (#14001). */
    private static array $streamContexts = [];

    private static int $nextFilterId = 1;

    public static function isBuiltinFilterName(string $name): bool
    {
        return isset(self::BUILTIN_FILTERS[strtolower($name)]);
    }

    public static function isKnownFilterName(string $name): bool
    {
        $name = strtolower($name);

        return self::isBuiltinFilterName($name)
            || \in_array($name, VmStreamFilters::registeredFilterNames(), true);
    }

    /**
     * @return int|false filter resource id
     */
    public static function append(
        int $streamHandle,
        string $filterName,
        int $readWrite,
        ?Frame $frame = null
    ) {
        return self::attach($streamHandle, $filterName, $readWrite, false, $frame);
    }

    /**
     * @return int|false filter resource id
     */
    public static function prepend(
        int $streamHandle,
        string $filterName,
        int $readWrite,
        ?Frame $frame = null
    ) {
        return self::attach($streamHandle, $filterName, $readWrite, true, $frame);
    }

    public static function isValidFilter(int $filterId): bool
    {
        return isset(self::$filters[$filterId]) && self::$filters[$filterId]['active'];
    }

    public static function filterHandle(Variable $target, int $filterId, ?\PHPCompiler\VM\Context $ctx = null): void
    {
        $target->streamFilterHandle($filterId, $ctx);
    }

    public static function getResourceType(int $filterId): ?string
    {
        return self::isValidFilter($filterId) ? 'stream filter' : null;
    }

    public static function remove(int $filterId): bool
    {
        if (!isset(self::$filters[$filterId]) || !self::$filters[$filterId]['active']) {
            return false;
        }
        $stream = self::$filters[$filterId]['stream'];
        self::$filters[$filterId]['active'] = false;
        self::$readChains[$stream] = array_values(array_filter(
            self::$readChains[$stream] ?? [],
            static fn (int $id): bool => $id !== $filterId
        ));
        self::$writeChains[$stream] = array_values(array_filter(
            self::$writeChains[$stream] ?? [],
            static fn (int $id): bool => $id !== $filterId
        ));

        return true;
    }

    public static function clearStream(int $streamHandle): void
    {
        unset(self::$readChains[$streamHandle], self::$writeChains[$streamHandle], self::$streamContexts[$streamHandle]);
        foreach (self::$filters as $id => $meta) {
            if ($meta['stream'] === $streamHandle) {
                unset(self::$filters[$id]);
            }
        }
    }

    public static function applyReadFilters(int $streamHandle, string $data): string
    {
        foreach (self::$readChains[$streamHandle] ?? [] as $filterId) {
            $data = self::applyFilter($filterId, $data);
        }

        return $data;
    }

    public static function applyWriteFilters(int $streamHandle, string $data): string
    {
        foreach (self::$writeChains[$streamHandle] ?? [] as $filterId) {
            $data = self::applyFilter($filterId, $data);
        }

        return $data;
    }

    /**
     * @return int|false
     */
    private static function attach(
        int $streamHandle,
        string $filterName,
        int $readWrite,
        bool $prepend,
        ?Frame $frame
    ) {
        $filterName = strtolower($filterName);
        if (!self::isKnownFilterName($filterName)) {
            self::warning(
                $frame,
                \sprintf('stream_filter_%s(): unable to locate filter "%s"', $prepend ? 'prepend' : 'append', $filterName)
            );

            return false;
        }
        $isUser = VmStreamFilters::isUserFilterName($filterName);
        if ($isUser) {
            if (null === $frame?->vmContext) {
                self::warning(
                    $frame,
                    \sprintf(
                        'stream_filter_%s(): user filter "%s" requires VM context in this compiler build',
                        $prepend ? 'prepend' : 'append',
                        $filterName
                    )
                );

                return false;
            }
            self::$streamContexts[$streamHandle] = $frame->vmContext;
        }
        if (0 === ($readWrite & (self::READ | self::WRITE))) {
            $readWrite = self::READ;
        }

        $filterId = self::$nextFilterId++;
        self::$filters[$filterId] = [
            'stream' => $streamHandle,
            'name' => $filterName,
            'active' => true,
            'user' => $isUser,
            'instance' => null,
            'oncreate' => false,
        ];

        if (0 !== ($readWrite & self::READ)) {
            if ($prepend) {
                self::$readChains[$streamHandle] ??= [];
                array_unshift(self::$readChains[$streamHandle], $filterId);
            } else {
                self::$readChains[$streamHandle] ??= [];
                self::$readChains[$streamHandle][] = $filterId;
            }
        }
        if (0 !== ($readWrite & self::WRITE)) {
            if ($prepend) {
                self::$writeChains[$streamHandle] ??= [];
                array_unshift(self::$writeChains[$streamHandle], $filterId);
            } else {
                self::$writeChains[$streamHandle] ??= [];
                self::$writeChains[$streamHandle][] = $filterId;
            }
        }

        return $filterId;
    }

    private static function applyFilter(int $filterId, string $data): string
    {
        if (!isset(self::$filters[$filterId]) || !self::$filters[$filterId]['active']) {
            return $data;
        }
        $meta = self::$filters[$filterId];
        if ($meta['user']) {
            return self::applyUserFilter($filterId, $data);
        }

        return self::transform($meta['name'], $data);
    }

    private static function applyUserFilter(int $filterId, string $data): string
    {
        $meta = self::$filters[$filterId];
        $ctx = self::$streamContexts[$meta['stream']] ?? null;
        if (null === $ctx) {
            return $data;
        }
        $className = VmStreamFilters::classForFilter($meta['name']);
        if (null === $className) {
            return $data;
        }
        $vm = $ctx->runtime->vm();
        $instance = $meta['instance'];
        if (null === $instance) {
            $instance = self::instantiateUserFilter($vm, $ctx, $className);
            if (null === $instance) {
                return $data;
            }
            self::$filters[$filterId]['instance'] = $instance;
            $instance->getProperty(PhpUserFilterBuiltin::PROP_FILTERNAME)->string($meta['name']);
            if (!self::$filters[$filterId]['oncreate']) {
                self::$filters[$filterId]['oncreate'] = true;
                if ($vm->hasInstanceMethod($instance->class, 'oncreate')) {
                    $created = $vm->invokeInstanceMethod($instance, 'oncreate')->resolveIndirect();
                    if (Variable::TYPE_BOOLEAN !== $created->type || !$created->toBool()) {
                        return $data;
                    }
                }
            }
        }

        $inBrigadeId = VmStreamBucket::allocateBrigade();
        $outBrigadeId = VmStreamBucket::allocateBrigade();
        $bucketVar = VmStreamBucket::newBucketObject($ctx, $meta['stream'], $data);
        VmStreamBucket::append($inBrigadeId, $bucketVar->toObject());

        $inVar = new Variable();
        VmStreamBucket::brigadeHandle($inVar, $inBrigadeId);
        $outVar = new Variable();
        VmStreamBucket::brigadeHandle($outVar, $outBrigadeId);
        $consumedVar = new Variable();
        $consumedVar->int(0);
        $consumedRef = new Variable();
        $consumedRef->indirect($consumedVar);
        $closingVar = new Variable();
        $closingVar->bool(false);

        $status = $vm->invokeInstanceMethod(
            $instance,
            'filter',
            $inVar,
            $outVar,
            $consumedRef,
            $closingVar
        )->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $status->type
            || StdlibConstants::PSFS_PASS_ON !== $status->toInt()
        ) {
            return $data;
        }

        return self::drainBrigade($ctx, $outBrigadeId);
    }

    private static function drainBrigade(Context $ctx, int $brigadeId): string
    {
        $chunks = [];
        while (true) {
            $bucketVar = VmStreamBucket::makeWriteable($ctx, $brigadeId);
            if (null === $bucketVar) {
                break;
            }
            $obj = $bucketVar->resolveIndirect()->toObject();
            $chunks[] = $obj->getProperty(VmStreamBucket::PROP_DATA)->resolveIndirect()->toString();
        }

        return \implode('', $chunks);
    }

    private static function instantiateUserFilter(VM $vm, Context $ctx, string $className): ?ObjectEntry
    {
        PhpUserFilterBuiltin::registerClass($ctx);
        if (!PhpUserFilterBuiltin::isSubclassOf($ctx, $className)) {
            return null;
        }
        $lc = strtolower($className);
        if (!isset($ctx->classes[$lc])) {
            $ctx->autoloadClass($className);
        }
        if (!isset($ctx->classes[$lc])) {
            return null;
        }
        $class = $ctx->classes[$lc];
        if ($class->isEnum || $class->isAbstract || $class->isInterface) {
            return null;
        }
        try {
            ClassValidator::assertInstantiable($class);
        } catch (\Error) {
            return null;
        }
        $object = new ObjectEntry($class);
        $vm->initInstancePropertyDefaults($object);
        $object->constructed = true;

        return $object;
    }

    private static function transform(string $filterName, string $data): string
    {
        return match ($filterName) {
            'string.rot13' => VmString::strRot13($data),
            'string.toupper' => VmString::asciiUpper($data),
            'string.tolower' => VmString::asciiLower($data),
            'convert.base64-encode' => \base64_encode($data),
            'convert.base64-decode' => (string) \base64_decode($data, true),
            'convert.quoted-printable-decode' => VmString::quoted_printable_decode($data),
            'convert.quoted-printable-encode' => VmString::quoted_printable_encode($data),
            default => $data,
        };
    }

    private static function warning(?Frame $frame, string $message): void
    {
        if (null === $frame?->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}
