<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Incremental zlib DeflateContext / InflateContext (php-src ext/zlib/zlib.c; issue #4656).
 *
 * Uses host ext-zlib when available for streaming parity; falls back to buffered VmZlibCore one-shot.
 */
final class VmZlibContext
{
    public const DEFLATE_CLASS_LC = 'deflatecontext';

    public const INFLATE_CLASS_LC = 'inflatecontext';

    /** @var array<int, array{kind: string, encoding: int, native?: object, buffer?: string, finished?: bool, level?: int, status?: int, total_in?: int}> */
    private static array $store = [];

    private static int $nativeNest = 0;

    public static function registerClasses(Context $ctx): void
    {
        foreach (['DeflateContext' => self::DEFLATE_CLASS_LC, 'InflateContext' => self::INFLATE_CLASS_LC] as $name => $lc) {
            if (isset($ctx->classes[$lc])) {
                continue;
            }
            $entry = new \PHPCompiler\VM\ClassEntry($name);
            $entry->isInternal = true;
            // php-src `final class InflateContext` / `DeflateContext` (ext/zlib/zlib.stub.php; #28385).
            $entry->isFinal = true;
            $ctx->classes[$lc] = $entry;
        }
    }

    public static function assertValidEncoding(int $encoding, string $function, int $position, string $paramName): void
    {
        if (
            \ZLIB_ENCODING_RAW === $encoding
            || \ZLIB_ENCODING_DEFLATE === $encoding
            || \ZLIB_ENCODING_GZIP === $encoding
            || 65534 === $encoding
            || 65535 === $encoding
            || 16 === $encoding
        ) {
            return;
        }

        if ('inflate_init' === $function) {
            throw new \ValueError(
                'Encoding mode must be ZLIB_ENCODING_RAW, ZLIB_ENCODING_GZIP or ZLIB_ENCODING_DEFLATE'
            );
        }

        throw new \ValueError(\sprintf(
            '%s(): Argument #%d ($%s) must be one of ZLIB_ENCODING_RAW, ZLIB_ENCODING_GZIP, or ZLIB_ENCODING_DEFLATE',
            $function,
            $position,
            $paramName
        ));
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function deflateInit(Context $vmCtx, int $encoding, array $options = []): Variable
    {
        self::assertValidEncoding($encoding, 'deflate_init', 1, 'encoding');
        $level = self::parseLevelOption($options, 'deflate_init');
        self::registerClasses($vmCtx);

        $class = $vmCtx->classes[self::DEFLATE_CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('DeflateContext is not registered in this compiler build');
        }

        $entry = new ObjectEntry($class);
        $entry->constructed = true;

        $native = self::tryNativeDeflateInit($encoding, $options);
        if (null !== $native) {
            self::$store[$entry->id] = [
                'kind' => 'deflate',
                'encoding' => $encoding,
                'native' => $native,
                'level' => $level,
            ];
        } else {
            self::$store[$entry->id] = [
                'kind' => 'deflate',
                'encoding' => $encoding,
                'buffer' => '',
                'finished' => false,
                'level' => $level,
            ];
        }

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function deflateAdd(ObjectEntry $entry, string $data, int $flush): string|false
    {
        $state = self::requireContext($entry, 'deflate_add', 1, self::DEFLATE_CLASS_LC, 'DeflateContext');
        if (isset($state['native'])) {
            return self::nativeDeflateAdd($state['native'], $data, $flush);
        }

        $state['buffer'] .= $data;
        if (\ZLIB_NO_FLUSH === $flush) {
            self::$store[$entry->id] = $state;

            return '';
        }

        $compressed = self::compressBuffered($state['encoding'], $state['buffer'], $state['level'] ?? -1);
        if (false === $compressed) {
            return false;
        }
        $state['buffer'] = '';
        if (\ZLIB_FINISH === $flush) {
            $state['finished'] = true;
        }
        self::$store[$entry->id] = $state;

        return $compressed;
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function inflateInit(Context $vmCtx, int $encoding, array $options = []): Variable
    {
        self::assertValidEncoding($encoding, 'inflate_init', 1, 'encoding');
        self::assertInflateWindowOption($options);
        self::registerClasses($vmCtx);

        $class = $vmCtx->classes[self::INFLATE_CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('InflateContext is not registered in this compiler build');
        }

        $entry = new ObjectEntry($class);
        $entry->constructed = true;

        $native = self::tryNativeInflateInit($encoding, $options);
        if (null !== $native) {
            self::$store[$entry->id] = [
                'kind' => 'inflate',
                'encoding' => $encoding,
                'native' => $native,
                'status' => 0,
                'total_in' => 0,
            ];
        } else {
            self::$store[$entry->id] = [
                'kind' => 'inflate',
                'encoding' => $encoding,
                'buffer' => '',
                'finished' => false,
                'status' => 0,
                'total_in' => 0,
            ];
        }

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function inflateAdd(ObjectEntry $entry, string $data, int $flush): string|false
    {
        $state = self::requireContext($entry, 'inflate_add', 1, self::INFLATE_CLASS_LC, 'InflateContext');
        if (isset($state['native'])) {
            $result = self::nativeInflateAdd($state['native'], $data, $flush);
            // Keep php-src total_in/status mirrors for getters when host getters are unavailable.
            $state['total_in'] = ($state['total_in'] ?? 0) + \strlen($data);
            if (false !== $result && \ZLIB_FINISH === $flush) {
                $state['status'] = 1; // ZLIB_STREAM_END
                $state['finished'] = true;
            }
            self::$store[$entry->id] = $state;

            return $result;
        }

        $state['buffer'] .= $data;
        $state['total_in'] = ($state['total_in'] ?? 0) + \strlen($data);
        if (\ZLIB_NO_FLUSH === $flush) {
            $partial = self::tryDecompressPartial($state['encoding'], $state['buffer']);
            self::$store[$entry->id] = $state;

            return false === $partial ? '' : $partial;
        }

        $plain = self::decompressBuffered($state['encoding'], $state['buffer']);
        if (false === $plain) {
            self::$store[$entry->id] = $state;

            return false;
        }
        $state['buffer'] = '';
        if (\ZLIB_FINISH === $flush) {
            $state['finished'] = true;
            $state['status'] = 1; // ZLIB_STREAM_END
        }
        self::$store[$entry->id] = $state;

        return $plain;
    }

    /** php-src PHP_FUNCTION(inflate_get_status) — usually ZLIB_OK (0) or ZLIB_STREAM_END (1) (#20008). */
    public static function inflateGetStatus(ObjectEntry $entry): int
    {
        $state = self::requireContext($entry, 'inflate_get_status', 1, self::INFLATE_CLASS_LC, 'InflateContext');
        if (isset($state['native'])) {
            $native = self::nativeInflateGetStatus($state['native']);
            if (null !== $native) {
                return $native;
            }
        }

        return (int) ($state['status'] ?? 0);
    }

    /** php-src PHP_FUNCTION(inflate_get_read_len) — z_stream.total_in (#20008). */
    public static function inflateGetReadLen(ObjectEntry $entry): int
    {
        $state = self::requireContext($entry, 'inflate_get_read_len', 1, self::INFLATE_CLASS_LC, 'InflateContext');
        if (isset($state['native'])) {
            $native = self::nativeInflateGetReadLen($state['native']);
            if (null !== $native) {
                return $native;
            }
        }

        return (int) ($state['total_in'] ?? 0);
    }

    public static function requireZlibContext(
        Variable $var,
        string $function,
        int $argNum,
        string $expectedLc,
        string $expectedName,
        string $paramName = 'context'
    ): ObjectEntry {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type %s, %s given',
                $function,
                $argNum,
                $paramName,
                $expectedName,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type %s, %s given',
                $function,
                $argNum,
                $paramName,
                $expectedName,
                VmStreamArg::debugTypeName($var)
            ));
        }
        $object = $var->toObject();
        if ($expectedLc !== strtolower($object->class->name)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type %s, %s given',
                $function,
                $argNum,
                $paramName,
                $expectedName,
                $object->class->name
            ));
        }
        if (!isset(self::$store[$object->id])) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type %s, %s given',
                $function,
                $argNum,
                $paramName,
                $expectedName,
                $object->class->name
            ));
        }

        return $object;
    }

    public static function parseOptionsVariable(Variable $var, string $function): array
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #2 ($options) must be of type array, %s given',
                $function,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        $options = [];
        foreach ($var->toArray()->iterateKeyed() as $pair) {
            /** @var Variable $keyVar */
            /** @var Variable $value */
            [$keyVar, $value] = $pair;
            $keyVar = $keyVar->resolveIndirect();
            if (Variable::TYPE_STRING !== $keyVar->type) {
                continue;
            }
            $key = $keyVar->toString();
            $value = $value->resolveIndirect();
            $intVal = null;
            if (Variable::TYPE_INTEGER === $value->type) {
                $intVal = $value->toInt();
            } elseif (Variable::TYPE_FLOAT === $value->type) {
                $intVal = (int) $value->toFloat();
            }
            if (null === $intVal) {
                continue;
            }
            // php-src ext/zlib/zlib.c — level/window/memory option table (#23642)
            if ('level' === $key || 'window' === $key || 'memory' === $key) {
                $options[$key] = $intVal;
            }
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function parseLevelOption(array $options, string $function): int
    {
        if (!isset($options['level'])) {
            return -1;
        }
        $level = $options['level'];
        if (!\is_int($level)) {
            throw new \TypeError(\sprintf(
                '%s(): "level" option must be of type int, %s given',
                $function,
                \get_debug_type($level)
            ));
        }
        if ($level < -1 || $level > 9) {
            throw new \ValueError(\sprintf(
                '%s(): "level" option must be between -1 and 9',
                $function
            ));
        }

        return $level;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function tryNativeDeflateInit(int $encoding, array $options): ?object
    {
        if (!\function_exists('deflate_init')) {
            return null;
        }
        if (++self::$nativeNest > 1) {
            --self::$nativeNest;

            return null;
        }
        try {
            $native = \deflate_init($encoding, $options);

            return false === $native ? null : $native;
        } finally {
            --self::$nativeNest;
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function assertInflateWindowOption(array $options): void
    {
        if (!isset($options['window'])) {
            return;
        }
        $window = $options['window'];
        if (!\is_int($window)) {
            throw new \TypeError(\sprintf(
                'inflate_init(): "window" option must be of type int, %s given',
                \get_debug_type($window)
            ));
        }
        if ($window < 8 || $window > 15) {
            // php-src ext/zlib/zlib.c — zlib_inflate_init window check
            throw new \ValueError(\sprintf(
                'zlib window size (logarithm) (%d) must be within 8..15',
                $window
            ));
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function tryNativeInflateInit(int $encoding, array $options = []): ?object
    {
        if (!\function_exists('inflate_init')) {
            return null;
        }
        if (++self::$nativeNest > 1) {
            --self::$nativeNest;

            return null;
        }
        try {
            $native = \inflate_init($encoding, $options);

            return false === $native ? null : $native;
        } finally {
            --self::$nativeNest;
        }
    }

    private static function nativeDeflateAdd(object $native, string $data, int $flush): string|false
    {
        if (++self::$nativeNest > 1) {
            --self::$nativeNest;

            return false;
        }
        try {
            return \deflate_add($native, $data, $flush);
        } finally {
            --self::$nativeNest;
        }
    }

    private static function nativeInflateAdd(object $native, string $data, int $flush): string|false
    {
        if (++self::$nativeNest > 1) {
            --self::$nativeNest;

            return false;
        }
        try {
            return \inflate_add($native, $data, $flush);
        } finally {
            --self::$nativeNest;
        }
    }

    private static function nativeInflateGetStatus(object $native): ?int
    {
        if (!\function_exists('inflate_get_status')) {
            return null;
        }
        if (++self::$nativeNest > 1) {
            --self::$nativeNest;

            return null;
        }
        try {
            return (int) \inflate_get_status($native);
        } finally {
            --self::$nativeNest;
        }
    }

    private static function nativeInflateGetReadLen(object $native): ?int
    {
        if (!\function_exists('inflate_get_read_len')) {
            return null;
        }
        if (++self::$nativeNest > 1) {
            --self::$nativeNest;

            return null;
        }
        try {
            return (int) \inflate_get_read_len($native);
        } finally {
            --self::$nativeNest;
        }
    }

    private static function compressBuffered(int $encoding, string $data, int $level): string|false
    {
        if (\ZLIB_ENCODING_GZIP === $encoding || 16 === $encoding) {
            return VmZlib::gzencode($data, $level, \ZLIB_ENCODING_GZIP);
        }
        if (\ZLIB_ENCODING_DEFLATE === $encoding || 65535 === $encoding) {
            return VmZlib::gzcompress($data, $level, \ZLIB_ENCODING_DEFLATE);
        }

        return VmZlib::gzdeflate($data, $level, \ZLIB_ENCODING_RAW);
    }

    private static function decompressBuffered(int $encoding, string $data): string|false
    {
        if (\ZLIB_ENCODING_GZIP === $encoding || 16 === $encoding) {
            return VmZlib::gzdecode($data);
        }
        if (\ZLIB_ENCODING_DEFLATE === $encoding || 65535 === $encoding) {
            return VmZlib::gzuncompress($data);
        }

        return VmZlib::gzinflate($data);
    }

    private static function tryDecompressPartial(int $encoding, string $data): string|false
    {
        $plain = self::decompressBuffered($encoding, $data);

        return $plain;
    }

    /**
     * @return array{kind: string, encoding: int, native?: object, buffer?: string, finished?: bool, level?: int, status?: int, total_in?: int}
     */
    private static function requireContext(
        ObjectEntry $entry,
        string $function,
        int $argNum,
        string $expectedLc,
        string $expectedName
    ): array {
        $state = self::$store[$entry->id] ?? null;
        if (null === $state || $expectedLc !== strtolower($entry->class->name)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($context) must be of type %s, %s given',
                $function,
                $argNum,
                $expectedName,
                $entry->class->name
            ));
        }

        return $state;
    }
}
