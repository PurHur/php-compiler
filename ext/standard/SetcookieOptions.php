<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * setcookie() / setrawcookie() argument parsing (positional + options array; issue #3507).
 *
 * php-src: ext/standard/head.c — php_setcookie()
 */
final class SetcookieOptions
{
    /** @var array<string, true> */
    private const VALID_SAMESITE = [
        'Lax' => true,
        'Strict' => true,
        'None' => true,
    ];

    /**
     * @param Variable[] $args
     *
     * @return array{
     *     name: string,
     *     value: string,
     *     expires: int,
     *     path: string,
     *     domain: string,
     *     secure: bool,
     *     httponly: bool,
     *     samesite: string,
     *     partitioned: bool,
     * }
     */
    public static function parseArgs(string $function, array $args): array
    {
        $argc = \count($args);
        if ($argc < 1) {
            throw new \ArgumentCountError($function.'() expects at least 1 argument, 0 given');
        }
        if ($argc >= 3) {
            $third = $args[2]->resolveIndirect();
            if (Variable::TYPE_ARRAY === $third->type) {
                if ($argc > 3) {
                    throw new \ArgumentCountError(
                        $function.'() expects at most 3 arguments when argument #3 is an array'
                    );
                }

                return self::parseOptionsArray(
                    $function,
                    VmString::coerceStringBuiltinArg($args[0], $function, 0, 'name'),
                    $argc >= 2
                        ? VmString::coerceStringBuiltinArg($args[1], $function, 1, 'value')
                        : '',
                    $third->toArray()
                );
            }
        }
        if ($argc > 7) {
            throw new \ArgumentCountError($function.'() accepts at most seven arguments');
        }

        return self::parsePositional($function, $args);
    }

    /**
     * @param Variable[] $args
     *
     * @return array{
     *     name: string,
     *     value: string,
     *     expires: int,
     *     path: string,
     *     domain: string,
     *     secure: bool,
     *     httponly: bool,
     *     samesite: string,
     *     partitioned: bool,
     * }
     */
    private static function parsePositional(string $function, array $args): array
    {
        $argc = \count($args);
        $name = VmString::coerceStringBuiltinArg($args[0], $function, 0, 'name');
        $value = '';
        if ($argc >= 2) {
            $value = VmString::coerceStringBuiltinArg($args[1], $function, 1, 'value');
        }
        $expires = 0;
        if ($argc >= 3) {
            $expires = VmMath::parseIntBuiltinArg($args[2], $function, 2, 'expires');
        }
        $path = '';
        if ($argc >= 4) {
            $path = VmString::coerceStringBuiltinArg($args[3], $function, 3, 'path');
        }
        $domain = '';
        if ($argc >= 5) {
            $domain = VmString::coerceStringBuiltinArg($args[4], $function, 4, 'domain');
        }
        $secure = false;
        if ($argc >= 6) {
            $secure = VmMath::parseBoolBuiltinArg($args[5], $function, 6, 'secure');
        }
        $httponly = false;
        if ($argc >= 7) {
            $httponly = VmMath::parseBoolBuiltinArg($args[6], $function, 7, 'httponly');
        }

        return self::pack($function, $name, $value, $expires, $path, $domain, $secure, $httponly, '', false);
    }

    /**
     * @return array{
     *     name: string,
     *     value: string,
     *     expires: int,
     *     path: string,
     *     domain: string,
     *     secure: bool,
     *     httponly: bool,
     *     samesite: string,
     *     partitioned: bool,
     * }
     */
    private static function parseOptionsArray(
        string $function,
        string $name,
        string $value,
        HashTable $options
    ): array {
        $expires = 0;
        $path = '';
        $domain = '';
        $secure = false;
        $httponly = false;
        $samesite = '';
        $partitioned = false;

        foreach ($options->iterateKeyed(true) as [$keyVar, $valVar]) {
            $key = $keyVar->resolveIndirect();
            if (Variable::TYPE_STRING !== $key->type) {
                continue;
            }
            $opt = $key->toString();
            switch ($opt) {
                case 'expires':
                    $expires = VmMath::parseIntBuiltinArg($valVar, $function, 3, 'expires');
                    break;
                case 'path':
                    $path = VmString::coerceStringBuiltinArg($valVar, $function, 3, 'path');
                    break;
                case 'domain':
                    $domain = VmString::coerceStringBuiltinArg($valVar, $function, 3, 'domain');
                    break;
                case 'secure':
                    $secure = VmMath::parseBoolBuiltinArg($valVar, $function, 3, 'secure');
                    break;
                case 'httponly':
                    $httponly = VmMath::parseBoolBuiltinArg($valVar, $function, 3, 'httponly');
                    break;
                case 'samesite':
                    $samesite = VmString::coerceStringBuiltinArg($valVar, $function, 3, 'samesite');
                    if ('' !== $samesite && !isset(self::VALID_SAMESITE[$samesite])) {
                        throw new \ValueError(
                            $function.'(): option "samesite" invalid; expected "Lax", "Strict", or "None"'
                        );
                    }
                    break;
                case 'partitioned':
                    $partitioned = VmMath::parseBoolBuiltinArg($valVar, $function, 3, 'partitioned');
                    break;
                default:
                    break;
            }
        }

        return self::pack($function, $name, $value, $expires, $path, $domain, $secure, $httponly, $samesite, $partitioned);
    }

    /**
     * Self-host spine smoke (#8698): exercise options-array parseArgs on the compile spine.
     *
     * @return array{
     *     name: string,
     *     value: string,
     *     expires: int,
     *     path: string,
     *     domain: string,
     *     secure: bool,
     *     httponly: bool,
     *     samesite: string,
     *     partitioned: bool,
     * }
     */
    public static function spineSmokeParse(): array
    {
        $name = new Variable();
        $name->string('spine');
        $value = new Variable();
        $value->string('ok');
        $optsHt = new HashTable();
        $pathVal = new Variable();
        $pathVal->string('/');
        $optsHt->addNew('path', $pathVal);
        $opts = new Variable();
        $opts->array($optsHt);

        return self::parseArgs('setcookie', [$name, $value, $opts]);
    }

    /**
     * @return array{
     *     name: string,
     *     value: string,
     *     expires: int,
     *     path: string,
     *     domain: string,
     *     secure: bool,
     *     httponly: bool,
     *     samesite: string,
     *     partitioned: bool,
     * }
     */
    private static function pack(
        string $function,
        string $name,
        string $value,
        int $expires,
        string $path,
        string $domain,
        bool $secure,
        bool $httponly,
        string $samesite,
        bool $partitioned
    ): array {
        VmString::rejectEmptyBuiltinStringArg($name, $function, 0, 'name');

        return [
            'name' => $name,
            'value' => $value,
            'expires' => $expires,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => $httponly,
            'samesite' => $samesite,
            'partitioned' => $partitioned,
        ];
    }
}
