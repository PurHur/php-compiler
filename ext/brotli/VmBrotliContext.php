<?php

declare(strict_types=1);

namespace PHPCompiler\ext\brotli;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmStreamArg;

/**
 * Brotli\Compress\Context / Brotli\UnCompress\Context opaque objects
 * (kjdev/php-ext-brotli brotli.stub.php; #27856).
 *
 * Streaming v1 buffers input until BROTLI_FINISH then uses
 * {@see VmBrotliNative::compress}/{@see VmBrotliNative::uncompress}.
 */
final class VmBrotliContext
{
    public const COMPRESS_CLASS = 'Brotli\\Compress\\Context';

    public const UNCOMPRESS_CLASS = 'Brotli\\UnCompress\\Context';

    public const COMPRESS_CLASS_LC = 'brotli\\compress\\context';

    public const UNCOMPRESS_CLASS_LC = 'brotli\\uncompress\\context';

    public const OP_PROCESS = 0;

    public const OP_FLUSH = 1;

    public const OP_FINISH = 2;

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

    public static function compressInit(Context $ctx, int $level, int $mode): Variable|false
    {
        if ($level < VmBrotliNative::MIN_QUALITY || $level > VmBrotliNative::MAX_QUALITY) {
            return false;
        }
        if ($mode < VmBrotliNative::MODE_GENERIC || $mode > VmBrotliNative::MODE_FONT) {
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
            'mode' => $mode,
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
            'level' => VmBrotliNative::DEFAULT_QUALITY,
            'mode' => VmBrotliNative::MODE_GENERIC,
            'buffer' => '',
            'finished' => false,
        ];
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }

    public static function compressAdd(Variable $contextVar, string $data, int $op): string|false
    {
        $object = self::requireObject($contextVar, 'brotli_compress_add', self::COMPRESS_CLASS_LC, self::COMPRESS_CLASS);
        if (!isset(self::$states[$object->id])) {
            throw new \Error(self::COMPRESS_CLASS.' is not a valid brotli streaming context');
        }
        $state = &self::$states[$object->id];
        if ($state['finished']) {
            return false;
        }
        $state['buffer'] .= $data;
        if (self::OP_FINISH !== $op) {
            return '';
        }
        $state['finished'] = true;
        $out = VmBrotliNative::compress($state['buffer'], $state['level'], $state['mode']);
        $state['buffer'] = '';

        return $out;
    }

    public static function uncompressAdd(Variable $contextVar, string $data, int $op): string|false
    {
        $object = self::requireObject($contextVar, 'brotli_uncompress_add', self::UNCOMPRESS_CLASS_LC, self::UNCOMPRESS_CLASS);
        if (!isset(self::$states[$object->id])) {
            throw new \Error(self::UNCOMPRESS_CLASS.' is not a valid brotli streaming context');
        }
        $state = &self::$states[$object->id];
        if ($state['finished']) {
            return false;
        }
        $state['buffer'] .= $data;
        if (self::OP_FINISH !== $op) {
            return '';
        }
        $state['finished'] = true;
        $out = VmBrotliNative::uncompress($state['buffer']);
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
