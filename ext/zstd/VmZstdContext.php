<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zstd;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmStreamArg;

/**
 * Zstd\Compress\Context / Zstd\UnCompress\Context opaque objects
 * (kjdev/php-ext-zstd zstd.stub.php; #27882).
 *
 * Streaming v1 buffers until end=true / final add then uses {@see VmZstdNative}.
 */
final class VmZstdContext
{
    public const COMPRESS_CLASS = 'Zstd\\Compress\\Context';

    public const UNCOMPRESS_CLASS = 'Zstd\\UnCompress\\Context';

    public const COMPRESS_CLASS_LC = 'zstd\\compress\\context';

    public const UNCOMPRESS_CLASS_LC = 'zstd\\uncompress\\context';

    public const LEVEL_MIN = 1;

    public const LEVEL_MAX = 22;

    public const LEVEL_DEFAULT = 3;

    /** @var array<int, array{kind: string, level: int, buffer: string, finished: bool}> */
    private static array $states = [];

    public static function registerClasses(Context $ctx): void
    {
        if (!isset($ctx->classes[self::COMPRESS_CLASS_LC])) {
            $entry = new ClassEntry(self::COMPRESS_CLASS);
            $entry->isInternal = true;
            $entry->isFinal = true;
            $ctx->classes[self::COMPRESS_CLASS_LC] = $entry;
        }
        if (!isset($ctx->classes[self::UNCOMPRESS_CLASS_LC])) {
            $entry = new ClassEntry(self::UNCOMPRESS_CLASS);
            $entry->isInternal = true;
            $entry->isFinal = true;
            $ctx->classes[self::UNCOMPRESS_CLASS_LC] = $entry;
        }
    }

    public static function compressInit(Context $ctx, int $level): Variable|false
    {
        if ($level < self::LEVEL_MIN || $level > self::LEVEL_MAX) {
            return false;
        }
        $class = $ctx->classes[self::COMPRESS_CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException(self::COMPRESS_CLASS.' is not registered in this compiler build');
        }
        $object = new ObjectEntry($class);
        $object->constructed = true;
        self::$states[$object->id] = [
            'kind' => 'compress',
            'level' => $level,
            'buffer' => '',
            'finished' => false,
        ];
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }

    public static function uncompressInit(Context $ctx): Variable|false
    {
        $class = $ctx->classes[self::UNCOMPRESS_CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException(self::UNCOMPRESS_CLASS.' is not registered in this compiler build');
        }
        $object = new ObjectEntry($class);
        $object->constructed = true;
        self::$states[$object->id] = [
            'kind' => 'uncompress',
            'level' => self::LEVEL_DEFAULT,
            'buffer' => '',
            'finished' => false,
        ];
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }

    public static function compressAdd(Variable $contextVar, string $data, bool $end): string|false
    {
        $object = self::requireObject($contextVar, 'zstd_compress_add', self::COMPRESS_CLASS_LC, self::COMPRESS_CLASS);
        if (!isset(self::$states[$object->id])) {
            throw new \Error(self::COMPRESS_CLASS.' is not a valid zstd streaming context');
        }
        $state = &self::$states[$object->id];
        if ($state['finished']) {
            return false;
        }
        $state['buffer'] .= $data;
        if (!$end) {
            return '';
        }
        $state['finished'] = true;
        $out = VmZstdNative::compress($state['buffer'], $state['level']);
        $state['buffer'] = '';

        return $out;
    }

    public static function uncompressAdd(Variable $contextVar, string $data): string|false
    {
        $object = self::requireObject($contextVar, 'zstd_uncompress_add', self::UNCOMPRESS_CLASS_LC, self::UNCOMPRESS_CLASS);
        if (!isset(self::$states[$object->id])) {
            throw new \Error(self::UNCOMPRESS_CLASS.' is not a valid zstd streaming context');
        }
        $state = &self::$states[$object->id];
        if ($state['finished']) {
            return false;
        }
        $state['buffer'] .= $data;
        // PECL uncompress_add has no end flag — decompress accumulated buffer each call
        // when buffer is a complete frame; v1 waits until buffer decompresses successfully.
        $out = VmZstdNative::decompress($state['buffer']);
        if (false === $out) {
            return '';
        }
        $state['finished'] = true;
        $state['buffer'] = '';

        return $out;
    }

    private static function requireObject(
        Variable $contextVar,
        string $function,
        string $classLc,
        string $className,
    ): ObjectEntry {
        $resolved = $contextVar->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $resolved->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($context) must be of type %s, %s given',
                $function,
                $className,
                VmStreamArg::debugTypeName($resolved)
            ));
        }
        $object = $resolved->toObject();
        if ($classLc !== strtolower($object->class->name)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($context) must be of type %s, %s given',
                $function,
                $className,
                $object->class->name
            ));
        }

        return $object;
    }
}
