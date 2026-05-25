<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * CGI env for examples/005-SessionsWeb AOT execute smokes (issue #1891).
 */
final class SessionsWebCgiEnv
{
    public const PROJECT_REL = 'examples/005-SessionsWeb';

    /**
     * @return array<string, string>
     */
    public static function getEmpty(): array
    {
        return [
            'REQUEST_METHOD' => 'GET',
            'SCRIPT_NAME' => '/example.php',
            'REQUEST_URI' => '/example.php',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function postFlash(string $message = 'Saved'): array
    {
        return [
            'REQUEST_METHOD' => 'POST',
            'QUERY_STRING' => 'message='.$message,
            'REQUEST_BODY' => 'message='.$message,
            'SCRIPT_NAME' => '/example.php',
            'REQUEST_URI' => '/example.php',
        ];
    }

    /**
     * Front-controller overlay for standalone AOT binary (issue #764 pattern).
     *
     * @return array<string, string>
     */
    public static function aotFrontController(string $repoRoot): array
    {
        $entry = realpath($repoRoot.'/'.self::PROJECT_REL.'/example.php');

        return [
            'SCRIPT_FILENAME' => false !== $entry ? $entry : $repoRoot.'/'.self::PROJECT_REL.'/example.php',
            'DOCUMENT_ROOT' => $repoRoot.'/'.self::PROJECT_REL,
        ];
    }
}
