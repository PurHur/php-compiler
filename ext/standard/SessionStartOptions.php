<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * session_start() options array parsing (php-src ext/session/session.c PHP_FUNCTION(session_start); #18457).
 */
final class SessionStartOptions
{
    /**
     * Apply session_start() runtime options before php_session_start().
     *
     * @return bool read_and_close flag
     */
    public static function apply(Frame $frame, HashTable $options): bool
    {
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            return false;
        }

        return self::applyJit($ctx, $options, $frame);
    }

    /**
     * @return bool read_and_close flag
     */
    public static function applyJit(Context $ctx, HashTable $options, ?Frame $frame = null): bool
    {
        $readAndClose = false;

        foreach ($options->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $key = $keyVar->resolveIndirect();
            if (Variable::TYPE_STRING !== $key->type) {
                continue;
            }
            $opt = $key->toString();
            $value = $valueVar->resolveIndirect();
            if ('read_and_close' === $opt) {
                $readAndClose = self::coerceOptionLong($value, 'read_and_close') !== 0;
                continue;
            }
            if (!self::isSupportedOptionValueType($value)) {
                throw new \TypeError(
                    'session_start(): Option "'.$opt.'" must be of type string|int|bool, '
                    .self::valueTypeName($value).' given'
                );
            }
            if (!self::applyOption($frame, $ctx, $opt, $value)) {
                TriggerErrorJitHelper::warning('session_start(): Setting option "'.$opt.'" failed');
            }
        }

        return $readAndClose;
    }

    private static function applyOption(?Frame $frame, Context $ctx, string $opt, Variable $value): bool
    {
        switch ($opt) {
            case 'name':
                if (!VmSession::canChangeName($frame)) {
                    return false;
                }
                $name = self::coerceOptionString($value);
                VmSession::setName($name);

                return true;
            case 'save_path':
                return false !== VmSession::setSavePath($frame, self::coerceOptionString($value));
            case 'cache_limiter':
                return false !== VmSession::setCacheLimiter($frame, self::coerceOptionString($value));
            case 'cache_expire':
                VmSession::setCacheExpire(self::coerceOptionLong($value, 'cache_expire'));

                return true;
            case 'cookie_lifetime':
                return self::applyCookiePartial($frame, 'lifetime', self::coerceOptionLong($value, 'cookie_lifetime'));
            case 'cookie_path':
                return self::applyCookiePartial($frame, 'path', self::coerceOptionString($value));
            case 'cookie_domain':
                return self::applyCookiePartial($frame, 'domain', self::coerceOptionString($value));
            case 'cookie_secure':
                return self::applyCookiePartial($frame, 'secure', self::coerceOptionBool($value));
            case 'cookie_httponly':
                return self::applyCookiePartial($frame, 'httponly', self::coerceOptionBool($value));
            case 'cookie_samesite':
                return self::applyCookiePartial($frame, 'samesite', self::coerceOptionString($value));
            case 'use_strict_mode':
                VmSession::setUseStrictMode(self::coerceOptionBool($value));

                return true;
            case 'gc_maxlifetime':
                return false !== VmIni::set(
                    $ctx,
                    'session.gc_maxlifetime',
                    (string) self::coerceOptionLong($value, 'gc_maxlifetime')
                );
            default:
                return false !== VmIni::set(
                    $ctx,
                    'session.'.$opt,
                    self::coerceOptionIniScalar($value)
                );
        }
    }

    private static function applyCookiePartial(?Frame $frame, string $field, int|string|bool $value): bool
    {
        $current = VmSession::getCookieParams();
        switch ($field) {
            case 'lifetime':
                $current['lifetime'] = (int) $value;
                break;
            case 'path':
                $current['path'] = (string) $value;
                break;
            case 'domain':
                $current['domain'] = (string) $value;
                break;
            case 'secure':
                $current['secure'] = (bool) $value;
                break;
            case 'httponly':
                $current['httponly'] = (bool) $value;
                break;
            case 'samesite':
                $current['samesite'] = (string) $value;
                break;
            default:
                return false;
        }

        return VmSession::applyCookieParams($frame, $current);
    }

    private static function isSupportedOptionValueType(Variable $value): bool
    {
        return match ($value->type) {
            Variable::TYPE_STRING,
            Variable::TYPE_INTEGER,
            Variable::TYPE_BOOLEAN => true,
            default => false,
        };
    }

    private static function coerceOptionString(Variable $value): string
    {
        return match ($value->type) {
            Variable::TYPE_STRING => $value->toString(),
            Variable::TYPE_INTEGER => (string) $value->toInt(),
            Variable::TYPE_BOOLEAN => $value->toBool() ? '1' : '0',
            default => '',
        };
    }

    private static function coerceOptionLong(Variable $value, string $opt): int
    {
        return match ($value->type) {
            Variable::TYPE_INTEGER => $value->toInt(),
            Variable::TYPE_BOOLEAN => $value->toBool() ? 1 : 0,
            Variable::TYPE_STRING => (int) $value->toString(),
            default => 0,
        };
    }

    private static function coerceOptionIniScalar(Variable $value): string
    {
        return match ($value->type) {
            Variable::TYPE_STRING => $value->toString(),
            Variable::TYPE_INTEGER => (string) $value->toInt(),
            Variable::TYPE_BOOLEAN => $value->toBool() ? '1' : '0',
            default => '',
        };
    }

    private static function coerceOptionBool(Variable $value): bool
    {
        return VmMath::parseBoolBuiltinArg($value, 'session_start', 1, 'options');
    }

    private static function valueTypeName(Variable $value): string
    {
        if (Variable::TYPE_OBJECT === $value->type) {
            return $value->toObject()->class->name;
        }
        if (Variable::TYPE_ARRAY === $value->type) {
            return 'array';
        }
        if (Variable::TYPE_NULL === $value->type) {
            return 'null';
        }
        if (Variable::TYPE_RESOURCE === $value->type) {
            return 'resource';
        }

        return match ($value->type) {
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            default => 'mixed',
        };
    }
}
