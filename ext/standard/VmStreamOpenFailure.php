<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;

/**
 * E_WARNING when fopen/file read fails for path-based builtins (php-src streams.c; #10625, #10441, #25288).
 */
final class VmStreamOpenFailure
{
    /**
     * @param string|null $detail strerror / wrapper reason; null → HTTP last transport err or ENOENT
     */
    public static function warnFailedToOpen(
        Frame $frame,
        string $function,
        string $path,
        ?string $detail = null
    ): void {
        if (null === $frame->vmContext) {
            return;
        }
        $message = self::failedToOpenMessage($function, $path, $detail);
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }

    /**
     * php-src streams.c-shaped message; records stream error store when 8.6 API is active.
     *
     * @param string|null $detail strerror / wrapper reason; null → HTTP last transport err or ENOENT
     */
    public static function failedToOpenMessage(
        string $function,
        string $path,
        ?string $detail = null
    ): string {
        if (null === $detail || '' === $detail) {
            $detail = self::resolveOpenFailureDetail($path);
        }
        VmStreamErrorStore::recordOpenFailed($path, $detail);

        return \sprintf(
            '%s(%s): Failed to open stream: %s',
            $function,
            $path,
            $detail
        );
    }

    /**
     * php-src streams.c — invalid mode / http connect strerror; plainfile defaults ENOENT.
     */
    private static function resolveOpenFailureDetail(string $path): string
    {
        $invalidMode = VmFopenMode::consumeLastOpenFailureDetail();
        if (null !== $invalidMode && '' !== $invalidMode) {
            return $invalidMode;
        }
        if (VmHttpLastResponseHeaders::isHttpUrl($path)) {
            $httpDetail = VmHttpFetchPure::lastOpenFailureDetail();
            if (null !== $httpDetail && '' !== $httpDetail) {
                return $httpDetail;
            }
        }
        $basedirDenied = VmOpenBasedir::consumeDeniedOpenDetail();
        if (null !== $basedirDenied) {
            return $basedirDenied;
        }

        return 'No such file or directory';
    }

    /**
     * php-src url.c / highlight helpers — open failure text before empty-path ValueError (#30514).
     */
    public static function highlightFailedOpeningMessage(string $function, string $path): string
    {
        return \sprintf(
            '%s(): Failed opening \'%s\' for highlighting',
            $function,
            $path
        );
    }

    public static function warnHighlightFailedOpening(Frame $frame, string $function, string $path): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            self::highlightFailedOpeningMessage($function, $path),
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}
