<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * session_set_cookie_params() / session_get_cookie_params() parsing (php-src ext/session/session.c; #9982).
 */
final class SessionCookieParams
{
    /**
     * @param Variable[] $args
     *
     * @return array{
     *     lifetime: int,
     *     path: string,
     *     domain: string,
     *     secure: bool,
     *     httponly: bool,
     *     samesite: string,
     * }
     */
    public static function parseSetArgs(string $function, array $args, ?Frame $frame = null): array
    {
        $argc = \count($args);
        if (0 === $argc) {
            throw new \ArgumentCountError($function.'() expects at least 1 argument, 0 given');
        }
        $first = $args[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY === $first->type) {
            if ($argc > 1) {
                throw new \ArgumentCountError(
                    $function.'() expects exactly 1 argument when argument #1 is an array, '.$argc.' given'
                );
            }

            return self::parseOptionsArray($function, $first->toArray(), $frame);
        }

        return self::parsePositional($function, $args);
    }

    /**
     * @param Variable[] $args
     *
     * @return array{
     *     lifetime: int,
     *     path: string,
     *     domain: string,
     *     secure: bool,
     *     httponly: bool,
     *     samesite: string,
     * }
     */
    private static function parsePositional(string $function, array $args): array
    {
        $argc = \count($args);
        if ($argc > 5) {
            throw new \ArgumentCountError(
                $function.'() expects at most 5 arguments, '.$argc.' given'
            );
        }
        $lifetime = VmMath::parseIntBuiltinArg($args[0], $function, 1, 'lifetime_or_options');
        $path = '/';
        if ($argc >= 2) {
            $path = VmString::coerceStringBuiltinArg($args[1], $function, 2, 'path');
        }
        $domain = '';
        if ($argc >= 3) {
            $domain = VmString::coerceStringBuiltinArg($args[2], $function, 3, 'domain');
        }
        $secure = false;
        if ($argc >= 4) {
            $secure = VmMath::parseBoolBuiltinArg($args[3], $function, 4, 'secure');
        }
        $httponly = false;
        if ($argc >= 5) {
            $httponly = VmMath::parseBoolBuiltinArg($args[4], $function, 5, 'httponly');
        }

        return self::pack($lifetime, $path, $domain, $secure, $httponly, '');
    }

    /**
     * @return array{
     *     lifetime: int,
     *     path: string,
     *     domain: string,
     *     secure: bool,
     *     httponly: bool,
     *     samesite: string,
     * }
     */
    private static function parseOptionsArray(string $function, HashTable $options, ?Frame $frame): array
    {
        $current = VmSession::getCookieParams();
        $lifetime = $current['lifetime'];
        $path = $current['path'];
        $domain = $current['domain'];
        $secure = $current['secure'];
        $httponly = $current['httponly'];
        $samesite = $current['samesite'];
        $validKeys = 0;

        foreach ($options->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $key = $keyVar->resolveIndirect();
            if (Variable::TYPE_STRING !== $key->type) {
                continue;
            }
            $opt = $key->toString();
            switch ($opt) {
                case 'lifetime':
                    $lifetime = VmMath::parseIntBuiltinArg($valueVar, $function, 1, 'lifetime_or_options');
                    ++$validKeys;
                    break;
                case 'path':
                    $path = VmString::coerceStringBuiltinArg($valueVar, $function, 1, 'path');
                    ++$validKeys;
                    break;
                case 'domain':
                    $domain = VmString::coerceStringBuiltinArg($valueVar, $function, 1, 'domain');
                    ++$validKeys;
                    break;
                case 'secure':
                    $secure = VmMath::parseBoolBuiltinArg($valueVar, $function, 1, 'secure');
                    ++$validKeys;
                    break;
                case 'httponly':
                    $httponly = VmMath::parseBoolBuiltinArg($valueVar, $function, 1, 'httponly');
                    ++$validKeys;
                    break;
                case 'samesite':
                    $samesite = VmString::coerceStringBuiltinArg($valueVar, $function, 1, 'samesite');
                    ++$validKeys;
                    break;
                default:
                    self::warnUnrecognizedKey($frame, $function, $opt);
                    break;
            }
        }
        if (0 === $validKeys) {
            throw new \ValueError(
                $function.'(): Argument #1 ($lifetime_or_options) must contain at least 1 valid key'
            );
        }

        return self::pack($lifetime, $path, $domain, $secure, $httponly, $samesite);
    }

    private static function warnUnrecognizedKey(?Frame $frame, string $function, string $key): void
    {
        if (null === $frame || null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            $function.'(): Argument #1 ($lifetime_or_options) contains an unrecognized key "'.$key.'"',
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame,
            $frame->callSiteLine
        );
    }

    /**
     * @return array{
     *     lifetime: int,
     *     path: string,
     *     domain: string,
     *     secure: bool,
     *     httponly: bool,
     *     samesite: string,
     * }
     */
    private static function pack(
        int $lifetime,
        string $path,
        string $domain,
        bool $secure,
        bool $httponly,
        string $samesite
    ): array {
        if ($lifetime < 0) {
            throw new \ValueError(
                'session_set_cookie_params(): Argument #1 ($lifetime_or_options) must be greater than or equal to 0'
            );
        }

        return [
            'lifetime' => $lifetime,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => $httponly,
            'samesite' => $samesite,
        ];
    }
}
