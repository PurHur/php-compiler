<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tidy;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmString;

/**
 * Host-bridge tidy helpers (php-src ext/tidy/tidy.c; #21464).
 *
 * When the harness PHP has ext/tidy, parse/cleanRepair delegate to host `\tidy_*`.
 * Without host tidy, parse returns false (compliance SKIPIF).
 */
final class VmTidy
{
    public const CLASS_LC = 'tidy';

    public const NODE_CLASS_LC = 'tidynode';

    /** @var array<int, object> ObjectEntry id => host \tidy instance */
    private static array $hostObjects = [];

    /** @var array<int, object> ObjectEntry id => host \tidyNode instance */
    private static array $hostNodes = [];

    public static function hostAvailable(): bool
    {
        return \extension_loaded('tidy') && \function_exists('tidy_parse_string');
    }

    public static function requireClass(Context $ctx): ClassEntry
    {
        if (!isset($ctx->classes[self::CLASS_LC])) {
            throw new \LogicException('tidy builtin class is not registered');
        }

        return $ctx->classes[self::CLASS_LC];
    }

    public static function requireNodeClass(Context $ctx): ClassEntry
    {
        if (!isset($ctx->classes[self::NODE_CLASS_LC])) {
            throw new \LogicException('tidyNode builtin class is not registered');
        }

        return $ctx->classes[self::NODE_CLASS_LC];
    }

    /**
     * @param array<string, mixed>|string|null $config
     *
     * @return Variable|false tidy object variable, or false when host tidy missing/parse fails
     */
    public static function parseString(Context $ctx, string $html, array|string|null $config = null, ?string $encoding = null, ?Frame $frame = null)
    {
        if (!self::hostAvailable()) {
            self::emitWarning($frame, 'tidy_parse_string(): host ext/tidy is not available');

            return false;
        }

        try {
            $host = \tidy_parse_string($html, $config, $encoding);
        } catch (\Throwable $e) {
            self::emitWarning($frame, 'tidy_parse_string(): '.$e->getMessage());

            return false;
        }
        if (false === $host || !\is_object($host)) {
            return false;
        }

        return self::wrapHost($ctx, $host);
    }

    /**
     * tidy_parse_file() — host bridge (#21501).
     *
     * @return Variable|false
     */
    /**
     * @param array<string, mixed>|string|null $config
     *
     * @return Variable|false
     */
    public static function parseFile(Context $ctx, string $filename, array|string|null $config = null, ?string $encoding = null, bool $useIncludePath = false, ?Frame $frame = null)
    {
        if (!self::hostAvailable() || !\function_exists('tidy_parse_file')) {
            self::emitWarning($frame, 'tidy_parse_file(): host ext/tidy is not available');

            return false;
        }

        try {
            $host = \tidy_parse_file($filename, $config, $encoding, $useIncludePath);
        } catch (\Throwable $e) {
            self::emitWarning($frame, 'tidy_parse_file(): '.$e->getMessage());

            return false;
        }
        if (false === $host || !\is_object($host)) {
            return false;
        }

        return self::wrapHost($ctx, $host);
    }

    /**
     * tidy::parseString() — reload host document into existing object (#21501).
     */
    /**
     * @param array<string, mixed>|string|null $config
     */
    public static function parseStringInto(ObjectEntry $object, string $html, array|string|null $config = null, ?string $encoding = null, ?Frame $frame = null): bool
    {
        if (!self::hostAvailable()) {
            self::emitWarning($frame, 'tidy::parseString(): host ext/tidy is not available');

            return false;
        }

        try {
            $host = \tidy_parse_string($html, $config, $encoding);
        } catch (\Throwable $e) {
            self::emitWarning($frame, 'tidy::parseString(): '.$e->getMessage());

            return false;
        }
        if (false === $host || !\is_object($host)) {
            return false;
        }
        self::$hostObjects[$object->id] = $host;
        self::syncHostProperties($object, $host);

        return true;
    }

    /**
     * tidy::parseFile() — reload host document into existing object (#21501).
     */
    /**
     * @param array<string, mixed>|string|null $config
     */
    public static function parseFileInto(ObjectEntry $object, string $filename, array|string|null $config = null, ?string $encoding = null, bool $useIncludePath = false, ?Frame $frame = null): bool
    {
        if (!self::hostAvailable() || !\function_exists('tidy_parse_file')) {
            self::emitWarning($frame, 'tidy::parseFile(): host ext/tidy is not available');

            return false;
        }

        try {
            $host = \tidy_parse_file($filename, $config, $encoding, $useIncludePath);
        } catch (\Throwable $e) {
            self::emitWarning($frame, 'tidy::parseFile(): '.$e->getMessage());

            return false;
        }
        if (false === $host || !\is_object($host)) {
            return false;
        }
        self::$hostObjects[$object->id] = $host;
        self::syncHostProperties($object, $host);

        return true;
    }

    public static function wrapHost(Context $ctx, object $host): Variable
    {
        $entry = new ObjectEntry(self::requireClass($ctx));
        $entry->constructed = true;
        self::$hostObjects[$entry->id] = $host;
        self::syncHostProperties($entry, $host);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    /**
     * tidy::__construct(?string $filename, array|string|null $config, ?string $encoding, bool $useIncludePath)
     * — host bridge (#21603). php-src ext/tidy/tidy.c PHP_METHOD(tidy, __construct).
     *
     * @param array<string, mixed>|string|null $config
     */
    public static function constructInto(
        ObjectEntry $object,
        ?string $filename,
        array|string|null $config,
        ?string $encoding,
        bool $useIncludePath,
        ?Frame $frame
    ): void {
        $object->constructed = true;

        if (self::hostAvailable()) {
            try {
                $host = new \tidy($filename, $config, $encoding, $useIncludePath);
            } catch (\Throwable $e) {
                throw $e;
            }
            self::$hostObjects[$object->id] = $host;
            self::syncHostProperties($object, $host);

            return;
        }

        if (null === $filename || '' === $filename) {
            return;
        }

        $contents = self::loadFileToMemory($filename, $useIncludePath);
        if (null === $contents) {
            throw new \Error(\sprintf(
                'Cannot load "%s" into memory%s',
                $filename,
                $useIncludePath ? ' (using include path)' : ''
            ));
        }
        // File readable but no host tidy to parse — soft-path empty document.
        self::emitWarning($frame, 'tidy::__construct(): host ext/tidy is not available');
    }

    /**
     * @return string|null file contents, or null when unreadable
     */
    private static function loadFileToMemory(string $filename, bool $useIncludePath): ?string
    {
        $data = @\file_get_contents($filename, $useIncludePath);
        if (false === $data) {
            return null;
        }

        return $data;
    }

    /**
     * Coerce tidy config argument (array|string|null) for host bridge (#21603).
     *
     * @return array<string, mixed>|string|null
     */
    public static function configArg(Variable $arg, string $func, int $argNum): array|string|null
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_NULL === $arg->type) {
            return null;
        }
        if (Variable::TYPE_STRING === $arg->type) {
            return $arg->toString();
        }
        if (Variable::TYPE_ARRAY === $arg->type) {
            $out = [];
            foreach ($arg->toArray()->exportKeyValuePairs(true) as [$keyVar, $valueVar]) {
                $key = match ($keyVar->type) {
                    Variable::TYPE_STRING => $keyVar->toString(),
                    Variable::TYPE_INTEGER => (string) $keyVar->toInt(),
                    default => (string) $keyVar->toString(),
                };
                $out[$key] = self::configScalar($valueVar);
            }

            return $out;
        }

        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($config) must be of type array|string|null, %s given',
            $func,
            $argNum + 1,
            self::typeLabel($arg)
        ));
    }

    /** @return mixed */
    private static function configScalar(Variable $value)
    {
        $value = $value->resolveIndirect();

        return match ($value->type) {
            Variable::TYPE_NULL => null,
            Variable::TYPE_BOOLEAN => $value->toBool(),
            Variable::TYPE_INTEGER => $value->toInt(),
            Variable::TYPE_FLOAT => $value->toFloat(),
            Variable::TYPE_STRING => $value->toString(),
            default => $value->toString(),
        };
    }

    public static function hostFrom(ObjectEntry $object): ?object
    {
        return self::$hostObjects[$object->id] ?? null;
    }

    public static function hostNodeFrom(ObjectEntry $object): ?object
    {
        return self::$hostNodes[$object->id] ?? null;
    }

    /**
     * Wrap host \tidyNode as VM tidyNode (#21543).
     *
     * @return Variable|null null when $host is not an object
     */
    public static function wrapHostNode(Context $ctx, mixed $host): ?Variable
    {
        if (null === $host || false === $host || !\is_object($host)) {
            return null;
        }

        $entry = new ObjectEntry(self::requireNodeClass($ctx));
        $entry->constructed = true;
        self::$hostNodes[$entry->id] = $host;
        self::syncNodeProperties($ctx, $entry, $host);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function requireTidyObject(Variable $arg, string $func, int $argNum): ObjectEntry
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $arg->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($tidy) must be of type tidy, %s given',
                $func,
                $argNum + 1,
                self::typeLabel($arg)
            ));
        }
        $object = $arg->toObject();
        if (self::CLASS_LC !== strtolower($object->class->name)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($tidy) must be of type tidy, %s given',
                $func,
                $argNum + 1,
                $object->class->name
            ));
        }

        return $object;
    }

    public static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => $var->toObject()->class->name,
            default => 'mixed',
        };
    }

    public static function cleanRepair(ObjectEntry $object, ?Frame $frame): bool
    {
        $host = self::hostFrom($object);
        if (null === $host) {
            self::emitWarning($frame, 'tidy::cleanRepair(): tidy object has no host backend');

            return false;
        }
        if (!\is_callable([$host, 'cleanRepair'])) {
            self::emitWarning($frame, 'tidy::cleanRepair(): host tidy lacks cleanRepair()');

            return false;
        }

        try {
            $ok = (bool) $host->cleanRepair();
        } catch (\Throwable $e) {
            self::emitWarning($frame, 'tidy::cleanRepair(): '.$e->getMessage());

            return false;
        }
        self::syncValueProperty($object, $host);

        return $ok;
    }

    /**
     * tidy_diagnose() / tidy::diagnose() — host bridge (#21500).
     */
    public static function diagnose(ObjectEntry $object, ?Frame $frame): bool
    {
        $host = self::hostFrom($object);
        if (null === $host) {
            self::emitWarning($frame, 'tidy_diagnose(): tidy object has no host backend');

            return false;
        }
        if (!\is_callable([$host, 'diagnose'])) {
            self::emitWarning($frame, 'tidy_diagnose(): host tidy lacks diagnose()');

            return false;
        }

        try {
            $ok = (bool) $host->diagnose();
        } catch (\Throwable $e) {
            self::emitWarning($frame, 'tidy_diagnose(): '.$e->getMessage());

            return false;
        }
        self::syncHostProperties($object, $host);

        return $ok;
    }

    /**
     * tidy_get_error_buffer() / $tidy->errorBuffer — host bridge (#21500).
     *
     * @return string|false
     */
    public static function getErrorBuffer(ObjectEntry $object, ?Frame $frame)
    {
        $host = self::hostFrom($object);
        if (null === $host) {
            self::emitWarning($frame, 'tidy_get_error_buffer(): tidy object has no host backend');

            return false;
        }
        try {
            if (\function_exists('tidy_get_error_buffer')) {
                $buf = \tidy_get_error_buffer($host);
            } else {
                $buf = $host->errorBuffer ?? false;
            }
        } catch (\Throwable $e) {
            self::emitWarning($frame, 'tidy_get_error_buffer(): '.$e->getMessage());

            return false;
        }
        if (false === $buf) {
            self::syncErrorBufferProperty($object, null);

            return false;
        }
        $str = (string) $buf;
        self::syncErrorBufferProperty($object, $str);

        return $str;
    }

    /**
     * tidy_get_output() / $tidy->value — host document string (#21499).
     */
    public static function getOutput(ObjectEntry $object, ?Frame $frame): string
    {
        $host = self::hostFrom($object);
        if (null === $host) {
            self::emitWarning($frame, 'tidy_get_output(): tidy object has no host backend');

            return '';
        }
        try {
            if (\function_exists('tidy_get_output')) {
                $out = \tidy_get_output($host);
            } else {
                $out = $host->value ?? '';
            }
        } catch (\Throwable $e) {
            self::emitWarning($frame, 'tidy_get_output(): '.$e->getMessage());

            return '';
        }
        $str = false === $out || null === $out ? '' : (string) $out;
        self::syncValueProperty($object, $host, $str);

        return $str;
    }

    /** Keep public $value in sync with host tidy (#21499). */
    public static function syncValueProperty(ObjectEntry $object, object $host, ?string $forced = null): void
    {
        if (!$object->hasProperty('value')) {
            return;
        }
        $slot = $object->getProperty('value');
        if (null !== $forced) {
            $slot->string($forced);

            return;
        }
        try {
            $raw = $host->value ?? null;
        } catch (\Throwable $e) {
            $slot->null();

            return;
        }
        if (null === $raw) {
            $slot->null();

            return;
        }
        $slot->string((string) $raw);
    }

    /** Keep public $errorBuffer in sync with host tidy (#21500). */
    public static function syncErrorBufferProperty(ObjectEntry $object, ?string $forced): void
    {
        if (!$object->hasProperty('errorBuffer')) {
            return;
        }
        $slot = $object->getProperty('errorBuffer');
        if (null === $forced) {
            $slot->null();

            return;
        }
        $slot->string($forced);
    }

    public static function syncHostProperties(ObjectEntry $object, object $host): void
    {
        self::syncValueProperty($object, $host);
        try {
            $buf = $host->errorBuffer ?? null;
        } catch (\Throwable $e) {
            self::syncErrorBufferProperty($object, null);

            return;
        }
        self::syncErrorBufferProperty($object, null === $buf ? null : (string) $buf);
    }

    /**
     * tidy_getopt() / tidy::getOpt() — host bridge (#21540).
     *
     * @return string|int|bool|null null when host missing / option lookup fails (caller maps to warning path)
     */
    public static function getOpt(ObjectEntry $object, string $option, ?Frame $frame)
    {
        $host = self::hostFrom($object);
        if (null === $host) {
            self::emitWarning($frame, 'tidy_getopt(): tidy object has no host backend');

            return null;
        }
        try {
            if (\function_exists('tidy_getopt')) {
                $val = \tidy_getopt($host, $option);
            } elseif (\is_callable([$host, 'getOpt'])) {
                $val = $host->getOpt($option);
            } else {
                self::emitWarning($frame, 'tidy_getopt(): host tidy lacks getOpt()');

                return null;
            }
        } catch (\Throwable $e) {
            self::emitWarning($frame, 'tidy_getopt(): '.$e->getMessage());

            return null;
        }

        return $val;
    }

    /**
     * tidy_get_opt_doc() / tidy::getOptDoc() — host bridge (#21604).
     *
     * php-src: ValueError on unknown option; false when docs unavailable; string otherwise.
     *
     * @return string|false|null null when soft-path (no host / no getOptDoc)
     */
    public static function getOptDoc(ObjectEntry $object, string $option, ?Frame $frame, bool $isMethod): string|false|null
    {
        $host = self::hostFrom($object);
        $label = $isMethod ? 'tidy::getOptDoc' : 'tidy_get_opt_doc';
        if (null === $host) {
            self::emitWarning($frame, $label.'(): tidy object has no host backend');

            return null;
        }
        try {
            if (\function_exists('tidy_get_opt_doc')) {
                $doc = \tidy_get_opt_doc($host, $option);
            } elseif (\is_callable([$host, 'getOptDoc'])) {
                $doc = $host->getOptDoc($option);
            } else {
                self::emitWarning($frame, $label.'(): host tidy lacks getOptDoc()');

                return null;
            }
        } catch (\ValueError $e) {
            throw $e;
        } catch (\Error $e) {
            // Host may throw Error/ValueError-shaped invalid option.
            throw $e;
        } catch (\Throwable $e) {
            self::emitWarning($frame, $label.'(): '.$e->getMessage());

            return null;
        }
        if (false === $doc) {
            return false;
        }

        return (string) $doc;
    }

    /**
     * tidy_get_config() / tidy::getConfig() — host bridge (#21540).
     *
     * @return array<string, mixed>|null
     */
    public static function getConfig(ObjectEntry $object, ?Frame $frame): ?array
    {
        $host = self::hostFrom($object);
        if (null === $host) {
            self::emitWarning($frame, 'tidy_get_config(): tidy object has no host backend');

            return null;
        }
        try {
            if (\function_exists('tidy_get_config')) {
                $cfg = \tidy_get_config($host);
            } elseif (\is_callable([$host, 'getConfig'])) {
                $cfg = $host->getConfig();
            } else {
                self::emitWarning($frame, 'tidy_get_config(): host tidy lacks getConfig()');

                return null;
            }
        } catch (\Throwable $e) {
            self::emitWarning($frame, 'tidy_get_config(): '.$e->getMessage());

            return null;
        }
        if (!\is_array($cfg)) {
            return [];
        }

        return $cfg;
    }

    /**
     * tidy_get_status() / tidy::getStatus() — host bridge (#21540).
     */
    public static function getStatus(ObjectEntry $object, ?Frame $frame): int
    {
        $host = self::hostFrom($object);
        if (null === $host) {
            self::emitWarning($frame, 'tidy_get_status(): tidy object has no host backend');

            return 0;
        }
        try {
            if (\function_exists('tidy_get_status')) {
                $status = \tidy_get_status($host);
            } elseif (\is_callable([$host, 'getStatus'])) {
                $status = $host->getStatus();
            } else {
                self::emitWarning($frame, 'tidy_get_status(): host tidy lacks getStatus()');

                return 0;
            }
        } catch (\Throwable $e) {
            self::emitWarning($frame, 'tidy_get_status(): '.$e->getMessage());

            return 0;
        }

        return (int) $status;
    }

    /** Assign string|int|bool return for tidy_getopt (#21540). */
    public static function assignOptValue(Variable $ret, string|int|bool $value): void
    {
        if (\is_bool($value)) {
            $ret->bool($value);

            return;
        }
        if (\is_int($value)) {
            $ret->int($value);

            return;
        }
        $ret->string($value);
    }

    /** Assign associative config array for tidy_get_config (#21540). */
    public static function assignConfigArray(Variable $ret, array $cfg): void
    {
        $ht = new HashTable();
        foreach ($cfg as $key => $item) {
            $slot = new Variable();
            if (\is_bool($item)) {
                $slot->bool($item);
            } elseif (\is_int($item)) {
                $slot->int($item);
            } elseif (\is_float($item)) {
                $slot->float($item);
            } elseif (null === $item) {
                $slot->null();
            } else {
                $slot->string((string) $item);
            }
            $ht->add(\is_int($key) ? (string) $key : (string) $key, $slot);
        }
        $ret->array($ht);
    }

    /**
     * tidy_*_count() family — host bridge (#21541).
     *
     * @param 'error'|'warning'|'access'|'config' $kind
     */
    public static function countKind(ObjectEntry $object, string $kind, ?Frame $frame): int
    {
        $host = self::hostFrom($object);
        $func = 'tidy_'.$kind.'_count';
        if (null === $host) {
            self::emitWarning($frame, $func.'(): tidy object has no host backend');

            return 0;
        }
        try {
            if (\function_exists($func)) {
                $n = $func($host);
            } else {
                self::emitWarning($frame, $func.'(): host tidy lacks '.$func);

                return 0;
            }
        } catch (\Throwable $e) {
            self::emitWarning($frame, $func.'(): '.$e->getMessage());

            return 0;
        }

        return (int) $n;
    }

    /**
     * tidy_get_release() / tidy::getRelease() — host bridge (#21542).
     */
    public static function getRelease(?Frame $frame): string
    {
        if (!self::hostAvailable() || !\function_exists('tidy_get_release')) {
            self::emitWarning($frame, 'tidy_get_release(): host ext/tidy is not available');

            return '';
        }
        try {
            return (string) \tidy_get_release();
        } catch (\Throwable $e) {
            self::emitWarning($frame, 'tidy_get_release(): '.$e->getMessage());

            return '';
        }
    }

    /**
     * tidy_get_html_ver() / tidy::getHtmlVer() — host bridge (#21542).
     */
    public static function getHtmlVer(ObjectEntry $object, ?Frame $frame): int
    {
        $host = self::hostFrom($object);
        if (null === $host) {
            self::emitWarning($frame, 'tidy_get_html_ver(): tidy object has no host backend');

            return 0;
        }
        try {
            if (\function_exists('tidy_get_html_ver')) {
                return (int) \tidy_get_html_ver($host);
            }
            if (\is_callable([$host, 'getHtmlVer'])) {
                return (int) $host->getHtmlVer();
            }
            self::emitWarning($frame, 'tidy_get_html_ver(): host tidy lacks getHtmlVer()');

            return 0;
        } catch (\Throwable $e) {
            self::emitWarning($frame, 'tidy_get_html_ver(): '.$e->getMessage());

            return 0;
        }
    }

    /**
     * tidy_is_xhtml() / tidy::isXhtml() — host bridge (#21542).
     */
    public static function isXhtml(ObjectEntry $object, ?Frame $frame): bool
    {
        $host = self::hostFrom($object);
        if (null === $host) {
            self::emitWarning($frame, 'tidy_is_xhtml(): tidy object has no host backend');

            return false;
        }
        try {
            if (\function_exists('tidy_is_xhtml')) {
                return (bool) \tidy_is_xhtml($host);
            }
            if (\is_callable([$host, 'isXhtml'])) {
                return (bool) $host->isXhtml();
            }
            self::emitWarning($frame, 'tidy_is_xhtml(): host tidy lacks isXhtml()');

            return false;
        } catch (\Throwable $e) {
            self::emitWarning($frame, 'tidy_is_xhtml(): '.$e->getMessage());

            return false;
        }
    }

    /**
     * tidy_is_xml() / tidy::isXml() — host bridge (#21542).
     */
    public static function isXml(ObjectEntry $object, ?Frame $frame): bool
    {
        $host = self::hostFrom($object);
        if (null === $host) {
            self::emitWarning($frame, 'tidy_is_xml(): tidy object has no host backend');

            return false;
        }
        try {
            if (\function_exists('tidy_is_xml')) {
                return (bool) \tidy_is_xml($host);
            }
            if (\is_callable([$host, 'isXml'])) {
                return (bool) $host->isXml();
            }
            self::emitWarning($frame, 'tidy_is_xml(): host tidy lacks isXml()');

            return false;
        } catch (\Throwable $e) {
            self::emitWarning($frame, 'tidy_is_xml(): '.$e->getMessage());

            return false;
        }
    }

    /**
     * tidy_repair_string() / tidy::repairString() — host bridge (#21498).
     *
     * @return string|false
     */
    /**
     * @param array<string, mixed>|string|null $config
     *
     * @return string|false
     */
    public static function repairString(string $html, array|string|null $config = null, ?string $encoding = null, ?Frame $frame = null)
    {
        if (!self::hostAvailable() || !\function_exists('tidy_repair_string')) {
            self::emitWarning($frame, 'tidy_repair_string(): host ext/tidy is not available');

            return false;
        }

        try {
            $out = \tidy_repair_string($html, $config, $encoding);
        } catch (\Throwable $e) {
            self::emitWarning($frame, 'tidy_repair_string(): '.$e->getMessage());

            return false;
        }
        if (false === $out) {
            return false;
        }

        return (string) $out;
    }

    /**
     * tidy_repair_file() / tidy::repairFile() — host bridge (#21498).
     *
     * @return string|false
     */
    /**
     * @param array<string, mixed>|string|null $config
     *
     * @return string|false
     */
    public static function repairFile(string $filename, array|string|null $config = null, ?string $encoding = null, bool $useIncludePath = false, ?Frame $frame = null)
    {
        if (!self::hostAvailable() || !\function_exists('tidy_repair_file')) {
            self::emitWarning($frame, 'tidy_repair_file(): host ext/tidy is not available');

            return false;
        }

        try {
            $out = \tidy_repair_file($filename, $config, $encoding, $useIncludePath);
        } catch (\Throwable $e) {
            self::emitWarning($frame, 'tidy_repair_file(): '.$e->getMessage());

            return false;
        }
        if (false === $out) {
            return false;
        }

        return (string) $out;
    }

    public static function htmlStringArg(Variable $arg, string $func, int $argNum): string
    {
        return VmString::coerceStringBuiltinArg($arg, $func, $argNum, 'string');
    }

    /**
     * Extract optional $config argument (array or string) from calledArgs at $index.
     *
     * php-src tidy_parse_string/tidy_repair_string accept array|string|null for $config.
     *
     * @param Variable[] $args
     *
     * @return array<string, mixed>|string|null
     */
    public static function optionalConfigArg(array $args, int $index, string $func): array|string|null
    {
        if (!isset($args[$index])) {
            return null;
        }
        $v = $args[$index]->resolveIndirect();
        if (Variable::TYPE_NULL === $v->type) {
            return null;
        }
        if (Variable::TYPE_STRING === $v->type) {
            return $v->toString();
        }
        $ht = $v->toArray();
        if (null !== $ht) {
            $out = [];
            foreach ($ht->iterateKeyed(true) as [$keyVar, $valueVar]) {
                $key = Variable::TYPE_INTEGER === $keyVar->type ? $keyVar->toInt() : $keyVar->toString();
                $out[$key] = self::variableToNative($valueVar);
            }

            return $out;
        }

        return null;
    }

    /**
     * Shallow conversion of a Variable to a native PHP scalar/array for host delegation.
     *
     * @return mixed
     */
    private static function variableToNative(Variable $v)
    {
        $v = $v->resolveIndirect();

        return match ($v->type) {
            Variable::TYPE_NULL => null,
            Variable::TYPE_INTEGER => $v->toInt(),
            Variable::TYPE_FLOAT => $v->toFloat(),
            Variable::TYPE_BOOLEAN => $v->toBool(),
            Variable::TYPE_STRING => $v->toString(),
            default => $v->toString(),
        };
    }

    /**
     * Extract optional $encoding argument (string) from calledArgs at $index.
     *
     * @param Variable[] $args
     */
    public static function optionalEncodingArg(array $args, int $index, string $func): ?string
    {
        if (!isset($args[$index])) {
            return null;
        }
        $v = $args[$index]->resolveIndirect();
        if (Variable::TYPE_NULL === $v->type) {
            return null;
        }

        return VmString::coerceStringBuiltinArg($args[$index], $func, $index, 'encoding');
    }

    /**
     * Extract optional $use_include_path argument (bool) from calledArgs at $index.
     *
     * @param Variable[] $args
     */
    public static function optionalUseIncludePathArg(array $args, int $index): bool
    {
        if (!isset($args[$index])) {
            return false;
        }
        $v = $args[$index]->resolveIndirect();

        return (bool) $v->toBool();
    }

    /**
     * tidy_get_root/html/head/body + tidy::{root,html,head,body} — host bridge (#21543).
     *
     * @param 'root'|'html'|'head'|'body' $kind
     *
     * @return Variable|null wrapped tidyNode, or null when missing/unavailable
     */
    public static function getDocumentNode(Context $ctx, ObjectEntry $object, string $kind, ?Frame $frame): ?Variable
    {
        $host = self::hostFrom($object);
        $procedural = 'tidy_get_'.$kind;
        $method = $kind;
        if (null === $host) {
            self::emitWarning($frame, $procedural.'(): tidy object has no host backend');

            return null;
        }
        try {
            if (\function_exists($procedural)) {
                $node = $procedural($host);
            } elseif (\is_callable([$host, $method])) {
                $node = $host->{$method}();
            } else {
                self::emitWarning($frame, $procedural.'(): host tidy lacks '.$method.'()');

                return null;
            }
        } catch (\Throwable $e) {
            self::emitWarning($frame, $procedural.'(): '.$e->getMessage());

            return null;
        }

        return self::wrapHostNode($ctx, $node);
    }

    public static function requireTidyNodeObject(Variable $arg, string $func, int $argNum): ObjectEntry
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $arg->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($node) must be of type tidyNode, %s given',
                $func,
                $argNum + 1,
                self::typeLabel($arg)
            ));
        }
        $object = $arg->toObject();
        if (self::NODE_CLASS_LC !== strtolower($object->class->name)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($node) must be of type tidyNode, %s given',
                $func,
                $argNum + 1,
                $object->class->name
            ));
        }

        return $object;
    }

    /**
     * tidyNode predicate / sibling helpers — host bridge (#21543).
     *
     * @param 'hasChildren'|'hasSiblings'|'isComment'|'isHtml'|'isText'|'isJste'|'isAsp'|'isPhp' $method
     */
    public static function nodeBoolMethod(ObjectEntry $object, string $method, ?Frame $frame): bool
    {
        $host = self::hostNodeFrom($object);
        if (null === $host) {
            self::emitWarning($frame, 'tidyNode::'.$method.'(): tidyNode has no host backend');

            return false;
        }
        if (!\is_callable([$host, $method])) {
            self::emitWarning($frame, 'tidyNode::'.$method.'(): host tidyNode lacks '.$method.'()');

            return false;
        }
        try {
            return (bool) $host->{$method}();
        } catch (\Throwable $e) {
            self::emitWarning($frame, 'tidyNode::'.$method.'(): '.$e->getMessage());

            return false;
        }
    }

    /**
     * tidyNode::getParent / getPreviousSibling / getNextSibling (#21543).
     *
     * @param 'getParent'|'getPreviousSibling'|'getNextSibling' $method
     */
    public static function nodeRelated(Context $ctx, ObjectEntry $object, string $method, ?Frame $frame): ?Variable
    {
        $host = self::hostNodeFrom($object);
        if (null === $host) {
            self::emitWarning($frame, 'tidyNode::'.$method.'(): tidyNode has no host backend');

            return null;
        }
        if (!\is_callable([$host, $method])) {
            self::emitWarning($frame, 'tidyNode::'.$method.'(): host tidyNode lacks '.$method.'()');

            return null;
        }
        try {
            $related = $host->{$method}();
        } catch (\Throwable $e) {
            self::emitWarning($frame, 'tidyNode::'.$method.'(): '.$e->getMessage());

            return null;
        }

        return self::wrapHostNode($ctx, $related);
    }

    /** Mirror host tidyNode readonly properties onto the VM object (#21543). */
    public static function syncNodeProperties(Context $ctx, ObjectEntry $object, object $host): void
    {
        self::syncNodeScalarString($object, 'value', $host);
        self::syncNodeScalarString($object, 'name', $host);
        self::syncNodeScalarInt($object, 'type', $host);
        self::syncNodeScalarInt($object, 'line', $host);
        self::syncNodeScalarInt($object, 'column', $host);
        self::syncNodeScalarBool($object, 'proprietary', $host);
        self::syncNodeNullableInt($object, 'id', $host);
        self::syncNodeAttributeProperty($object, $host);
        self::syncNodeChildProperty($ctx, $object, $host);
    }

    private static function syncNodeScalarString(ObjectEntry $object, string $prop, object $host): void
    {
        if (!$object->hasProperty($prop)) {
            return;
        }
        $slot = $object->getProperty($prop);
        try {
            $raw = $host->{$prop} ?? null;
        } catch (\Throwable $e) {
            $slot->string('');

            return;
        }
        $slot->string(null === $raw ? '' : (string) $raw);
    }

    private static function syncNodeScalarInt(ObjectEntry $object, string $prop, object $host): void
    {
        if (!$object->hasProperty($prop)) {
            return;
        }
        $slot = $object->getProperty($prop);
        try {
            $raw = $host->{$prop} ?? 0;
        } catch (\Throwable $e) {
            $slot->int(0);

            return;
        }
        $slot->int((int) $raw);
    }

    private static function syncNodeScalarBool(ObjectEntry $object, string $prop, object $host): void
    {
        if (!$object->hasProperty($prop)) {
            return;
        }
        $slot = $object->getProperty($prop);
        try {
            $raw = $host->{$prop} ?? false;
        } catch (\Throwable $e) {
            $slot->bool(false);

            return;
        }
        $slot->bool((bool) $raw);
    }

    private static function syncNodeNullableInt(ObjectEntry $object, string $prop, object $host): void
    {
        if (!$object->hasProperty($prop)) {
            return;
        }
        $slot = $object->getProperty($prop);
        try {
            $raw = $host->{$prop} ?? null;
        } catch (\Throwable $e) {
            $slot->null();

            return;
        }
        if (null === $raw) {
            $slot->null();

            return;
        }
        $slot->int((int) $raw);
    }

    private static function syncNodeAttributeProperty(ObjectEntry $object, object $host): void
    {
        if (!$object->hasProperty('attribute')) {
            return;
        }
        $slot = $object->getProperty('attribute');
        try {
            $raw = $host->attribute ?? null;
        } catch (\Throwable $e) {
            $slot->null();

            return;
        }
        if (null === $raw || !\is_array($raw)) {
            $slot->null();

            return;
        }
        $ht = new HashTable();
        foreach ($raw as $key => $item) {
            $cell = new Variable();
            $cell->string((string) $item);
            $ht->add(\is_int($key) ? (string) $key : (string) $key, $cell);
        }
        $slot->array($ht);
    }

    private static function syncNodeChildProperty(Context $ctx, ObjectEntry $object, object $host): void
    {
        if (!$object->hasProperty('child')) {
            return;
        }
        $slot = $object->getProperty('child');
        try {
            $raw = $host->child ?? null;
        } catch (\Throwable $e) {
            $slot->null();

            return;
        }
        if (null === $raw || !\is_array($raw)) {
            $slot->null();

            return;
        }
        $ht = new HashTable();
        foreach ($raw as $key => $item) {
            $wrapped = self::wrapHostNode($ctx, $item);
            if (null === $wrapped) {
                continue;
            }
            $ht->add(\is_int($key) ? (string) $key : (string) $key, $wrapped);
        }
        $slot->array($ht);
    }

    /** Assign nullable tidyNode return (#21543). */
    public static function assignNullableNode(Variable $ret, ?Variable $node): void
    {
        if (null === $node) {
            $ret->null();

            return;
        }
        $ret->copyFrom($node);
    }

    private static function emitWarning(?Frame $frame, string $message): void
    {
        if (null === $frame?->vmContext) {
            @\trigger_error($message, \E_WARNING);

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
