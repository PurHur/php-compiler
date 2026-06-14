<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * error_log() dispatch (php-src ext/standard/basic_functions.c::_php_error_log).
 *
 * PHP-in-PHP: no runtime/*.c — stderr via fwrite; file append via VmFsWriteNative (#8613).
 */
final class VmErrorLog
{
    public static function errorLog(
        int $messageType,
        string $message,
        ?string $destination = null,
        ?string $extraHeaders = null
    ): bool {
        switch ($messageType) {
            case 1:
                if (null === $destination || '' === $destination) {
                    return false;
                }

                return false;

            case 2:
                throw new \ValueError('TCP/IP option is not available for error logging');

            case 3:
                if (null === $destination || '' === $destination) {
                    throw new \ValueError('Path cannot be empty');
                }
                $written = VmFs::filePutContents(
                    $destination,
                    $message,
                    \LOCK_EX | StdlibConstants::FILE_APPEND
                );
                if (false === $written) {
                    return false;
                }

                return $written === \strlen($message);

            case 4:
                return self::logToStderr($message);

            default:
                return self::logToStderr($message);
        }
    }

    private static function logToStderr(string $message): bool
    {
        $payload = $message;
        if ('' === $message || "\n" !== \substr($message, -1)) {
            $payload = $message."\n";
        }

        return false !== @\fwrite(\STDERR, $payload);
    }
}
