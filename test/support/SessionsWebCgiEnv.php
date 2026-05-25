<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * CGI env contract for examples/005-SessionsWeb AOT execute smokes (#1891).
 *
 * @see test/unit/SessionsWebAotExecuteTest.php
 * @see script/examples-aot-smoke.sh (005 slice)
 */
final class SessionsWebCgiEnv
{
    public const PROJECT_REL = 'examples/005-SessionsWeb';

    /**
     * @return array<string, string>
     */
    public static function base(): array
    {
        return [
            'SCRIPT_NAME' => '/example.php',
            'REQUEST_URI' => '/example.php',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getEmpty(): array
    {
        return array_merge(self::base(), [
            'REQUEST_METHOD' => 'GET',
        ]);
    }

    /**
     * @return array<string, string>
     */
    public static function postFlash(string $message = 'Saved'): array
    {
        $body = 'message='.rawurlencode($message);

        return array_merge(self::base(), [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_BODY' => $body,
            'CONTENT_LENGTH' => (string) strlen($body),
        ]);
    }
}
