<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\ResponseContext;
use PHPLLVM\Value;

/**
 * setcookie() — emit Set-Cookie response header (VM ResponseContext + JIT pending queue; issue #63, #1170).
 */
final class setcookie extends Internal
{
    public function __construct()
    {
        parent::__construct('setcookie');
    }

    public function execute(Frame $frame): void
    {
        $parsed = self::parseArgs($frame->calledArgs);
        $ok = \setcookie(
            $parsed['name'],
            $parsed['value'],
            $parsed['expires'],
            $parsed['path'],
            $parsed['domain'],
            $parsed['secure'],
            $parsed['httponly']
        );
        ResponseContext::addHeader(SetcookieLine::build(
            $parsed['name'],
            $parsed['value'],
            $parsed['expires'],
            $parsed['path'],
            $parsed['domain'],
            $parsed['secure'],
            $parsed['httponly']
        ), false);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 7) {
            throw new \LogicException('setcookie() accepts one to seven arguments');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type) {
            throw new \LogicException('setcookie() name must be a string in this compiler build');
        }
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $namePtr = $this->jitString($context, $args[0], 'setcookie() name');
        $valuePtr = $context->builder->load($context->constantStringFromString(''));
        if ($argc >= 2) {
            if (JITVariable::TYPE_STRING !== $args[1]->type) {
                throw new \LogicException('setcookie() value must be a string in this compiler build');
            }
            $valuePtr = $this->jitString($context, $args[1], 'setcookie() value');
        }
        $expiresI64 = $i64->constInt(0, false);
        if ($argc >= 3) {
            JitLongArg::lower($context, $args[2], 'setcookie() expires');
            if (JITVariable::TYPE_NATIVE_LONG !== $args[2]->type) {
                throw new \LogicException('setcookie() expires must be an integer in this compiler build');
            }
            $expiresI64 = $context->builder->sext($args[2]->value, $i64);
        }
        $pathPtr = $context->builder->load($context->constantStringFromString(''));
        if ($argc >= 4) {
            if (JITVariable::TYPE_STRING !== $args[3]->type) {
                throw new \LogicException('setcookie() path must be a string in this compiler build');
            }
            $pathPtr = $this->jitString($context, $args[3], 'setcookie() path');
        }
        $domainPtr = $context->builder->load($context->constantStringFromString(''));
        if ($argc >= 5) {
            if (JITVariable::TYPE_STRING !== $args[4]->type) {
                throw new \LogicException('setcookie() domain must be a string in this compiler build');
            }
            $domainPtr = $this->jitString($context, $args[4], 'setcookie() domain');
        }
        $secureI32 = $i32->constInt(0, false);
        if ($argc >= 6) {
            $secureI32 = $context->builder->zExt(
                $this->jitBool($context, $args[5], 'setcookie() secure'),
                $i32
            );
        }
        $httponlyI32 = $i32->constInt(0, false);
        if ($argc >= 7) {
            $httponlyI32 = $context->builder->zExt(
                $this->jitBool($context, $args[6], 'setcookie() httponly'),
                $i32
            );
        }

        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            JitSetcookie::emitPending(
                $context,
                $namePtr,
                $valuePtr,
                $expiresI64,
                $pathPtr,
                $domainPtr,
                $secureI32,
                $httponlyI32
            );

            return $context->constantFromBool(true);
        }

        if (null === self::compileTimeArgs($args)) {
            throw new \LogicException(
                'setcookie() JIT requires compile-time constant arguments (name/value/path; expires 0) in this compiler build'
            );
        }
        JitSetcookie::emitPrintf(
            $context,
            $namePtr,
            $valuePtr,
            $argc >= 4 ? $pathPtr : null
        );

        return $context->constantFromBool(true);
    }

    /**
     * @param JITVariable[] $args
     *
     * @return array{name: string, value: string, expires: int, path: string, domain: string, secure: bool, httponly: bool}|null
     */
    private static function compileTimeArgs(array $args): ?array
    {
        $argc = \count($args);
        $name = JitStringArg::compileTimeLiteral($args[0]);
        if (null === $name) {
            return null;
        }
        $value = '';
        if ($argc >= 2) {
            $v = JitStringArg::compileTimeLiteral($args[1]);
            if (null === $v) {
                return null;
            }
            $value = $v;
        }
        $expires = 0;
        if ($argc >= 3) {
            if (JITVariable::TYPE_NATIVE_LONG !== $args[2]->type) {
                return null;
            }
            $e = self::nativeLongLiteral($args[2]);
            $expires = null === $e ? 0 : $e;
        }
        $path = '';
        if ($argc >= 4) {
            $p = JitStringArg::compileTimeLiteral($args[3]);
            if (null === $p) {
                return null;
            }
            $path = $p;
        }
        $domain = '';
        if ($argc >= 5) {
            return null;
        }
        $secure = false;
        $httponly = false;

        return [
            'name' => $name,
            'value' => $value,
            'expires' => $expires,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => $httponly,
        ];
    }

    private static function nativeLongLiteral(JITVariable $var): ?int
    {
        if (null !== $var->compileTimeString && is_numeric($var->compileTimeString)) {
            return (int) $var->compileTimeString;
        }

        return null;
    }

    /**
     * @param Variable[] $args
     *
     * @return array{name: string, value: string, expires: int, path: string, domain: string, secure: bool, httponly: bool}
     */
    private static function parseArgs(array $args): array
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 7) {
            throw new \LogicException('setcookie() accepts one to seven arguments');
        }
        $name = $args[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $name->type) {
            throw new \LogicException('setcookie() name must be a string in this compiler build');
        }
        $value = '';
        if ($argc >= 2) {
            $v = $args[1]->resolveIndirect();
            if (Variable::TYPE_STRING !== $v->type) {
                throw new \LogicException('setcookie() value must be a string in this compiler build');
            }
            $value = $v->toString();
        }
        $expires = 0;
        if ($argc >= 3) {
            $e = $args[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $e->type) {
                throw new \LogicException('setcookie() expires must be an integer in this compiler build');
            }
            $expires = $e->toInt();
        }
        $path = '';
        if ($argc >= 4) {
            $p = $args[3]->resolveIndirect();
            if (Variable::TYPE_STRING !== $p->type) {
                throw new \LogicException('setcookie() path must be a string in this compiler build');
            }
            $path = $p->toString();
        }
        $domain = '';
        if ($argc >= 5) {
            $d = $args[4]->resolveIndirect();
            if (Variable::TYPE_STRING !== $d->type) {
                throw new \LogicException('setcookie() domain must be a string in this compiler build');
            }
            $domain = $d->toString();
        }
        $secure = false;
        if ($argc >= 6) {
            $s = $args[5]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $s->type) {
                throw new \LogicException('setcookie() secure must be a boolean in this compiler build');
            }
            $secure = $s->toBool();
        }
        $httponly = false;
        if ($argc >= 7) {
            $h = $args[6]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $h->type) {
                throw new \LogicException('setcookie() httponly must be a boolean in this compiler build');
            }
            $httponly = $h->toBool();
        }

        return [
            'name' => $name->toString(),
            'value' => $value,
            'expires' => $expires,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => $httponly,
        ];
    }

}
