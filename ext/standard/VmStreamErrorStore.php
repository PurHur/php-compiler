<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * PHP 8.6 stream error store — stream_last_errors() / stream_clear_errors() (#21020).
 *
 * php-src: main/streams/stream_errors.c — php_stream_error_get_last / php_stream_error_clear_stored
 * php-src: main/streams/stream_errors.stub.php — StreamError / StreamErrorCode
 */
final class VmStreamErrorStore
{
    /** @var list<array{code: string, message: string, wrapperName: string, severity: int, terminating: bool, param: ?string}> */
    private static array $stored = [];

    public static function clear(): void
    {
        self::$stored = [];
    }

    /**
     * Record a terminating open failure (php-src OpenFailed; default Error mode stores none —
     * this compiler stores open failures when the 8.6 API is active so the #21020 repro matches).
     */
    public static function recordOpenFailed(string $path, string $detail = 'No such file or directory'): void
    {
        if (!CompilerVersion::supportsStreamErrorApi()) {
            return;
        }
        $wrapper = self::wrapperNameForPath($path);
        self::$stored = [[
            'code' => 'OpenFailed',
            'message' => 'Failed to open stream: '.$detail,
            'wrapperName' => $wrapper,
            'severity' => ErrorReporter::E_WARNING,
            'terminating' => true,
            'param' => null,
        ]];
    }

    public static function lastErrorsVariable(Context $ctx): Variable
    {
        $ht = new HashTable();
        foreach (self::$stored as $entry) {
            $ht->append(StreamErrorBuiltin::createObject(
                $ctx,
                $entry['code'],
                $entry['message'],
                $entry['wrapperName'],
                $entry['severity'],
                $entry['terminating'],
                $entry['param']
            ));
        }
        $out = new Variable(Variable::TYPE_ARRAY);
        $out->array($ht);

        return $out;
    }

    private static function wrapperNameForPath(string $path): string
    {
        if (\str_starts_with($path, 'php://')) {
            return 'PHP';
        }
        if (\str_contains($path, '://')) {
            $scheme = \strstr($path, '://', true);

            return false !== $scheme && '' !== $scheme ? $scheme : 'plainfile';
        }

        return 'plainfile';
    }
}
