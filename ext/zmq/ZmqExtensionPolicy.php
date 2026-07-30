<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zmq;

/**
 * ext/zmq advertisement — pecl-networking-zmq (#6443, #23964).
 *
 * Pure-PHP inproc zmq_* / ZMQ* classes stay compiled in-tree but must not flip
 * {@code extension_loaded('zmq')} / {@code class_exists('ZMQContext')} when host
 * Zend has no pecl-zmq — same host-module gate as zip/enchant (#25010 / #23963).
 *
 * Enable via host {@code extension_loaded('zmq')}, or explicit
 * {@code PHP_COMPILER_ENABLE_ZMQ=1} (functional PHPT / local runs).
 */
final class ZmqExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        if (\extension_loaded('zmq')) {
            return true;
        }

        return self::explicitEnableRequested();
    }

    public static function advertisesBuiltins(): bool
    {
        return self::advertisesExtension();
    }

    public static function advertisesClasses(): bool
    {
        return self::advertisesExtension();
    }

    /** Compliance filenames that exercise zmq_* / ZMQ* / extension_loaded('zmq'). */
    public static function isZmqComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'zmq')
            || str_contains($testFileName, 'extension_loaded_zmq');
    }

    /** Phantom-registration guards that assert zmq is withheld (#23964). */
    public static function isZmqPhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'zmq_phantom')
            || str_contains($testFileName, 'extension_loaded_zmq_phantom')
            || str_contains($testFileName, 'maintainer_gap_zmq_extension_phantom');
    }

    /**
     * Functional zmq cases set {@code PHP_COMPILER_ENABLE_ZMQ} via {@code --ENV--}; module
     * phantom guards run only when zmq is withheld (#23964).
     */
    public static function runsZmqCompliance(string $testFileName): bool
    {
        if (self::isZmqPhantomComplianceCase($testFileName)) {
            return !self::advertisesExtension();
        }

        return true;
    }

    /** Explicit side-load / functional-test opt-in when host Zend lacks pecl-zmq (#23964). */
    private static function explicitEnableRequested(): bool
    {
        $raw = getenv('PHP_COMPILER_ENABLE_ZMQ');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        $v = strtolower(trim($raw));

        return !\in_array($v, ['0', 'false', 'off', 'no'], true);
    }
}
