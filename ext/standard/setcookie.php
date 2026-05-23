<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\ResponseContext;
use PHPLLVM\Value;

/**
 * setcookie() — emit Set-Cookie response header (VM ResponseContext + JIT printf; issue #63).
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
        $namePtr = $this->jitString($context, $args[0], 'setcookie() name');
        $valuePtr = $context->builder->load($context->constantStringFromString(''));
        if ($argc >= 2) {
            if (JITVariable::TYPE_STRING !== $args[1]->type) {
                throw new \LogicException('setcookie() value must be a string in this compiler build');
            }
            $valuePtr = $this->jitString($context, $args[1], 'setcookie() value');
        }
        $pathPtr = null;
        if ($argc >= 4 && JITVariable::TYPE_STRING === $args[3]->type) {
            $pathPtr = $this->jitString($context, $args[3], 'setcookie() path');
        } elseif ($argc >= 4) {
            throw new \LogicException('setcookie() path must be a string in this compiler build');
        }
        if ($argc >= 3) {
            JitLongArg::lower($context, $args[2], 'setcookie() expires');
            if (JITVariable::TYPE_NATIVE_LONG !== $args[2]->type) {
                throw new \LogicException('setcookie() expires must be an integer in this compiler build');
            }
        }
        if ($argc >= 5) {
            throw new \LogicException(
                'setcookie() with domain/secure/httponly is VM-only in this compiler build; use header() in JIT'
            );
        }
        JitSetcookie::emit($context, $namePtr, $valuePtr, $pathPtr);

        return $context->constantFromBool(true);
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
