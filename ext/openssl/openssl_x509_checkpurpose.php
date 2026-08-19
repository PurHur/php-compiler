<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * openssl_x509_checkpurpose() — X509 purpose / trust check (php-src ext/openssl/openssl.c; #20286 VM, JIT/AOT #32522).
 */
final class openssl_x509_checkpurpose extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_x509_checkpurpose');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(
                'openssl_x509_checkpurpose() expects at least 2 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $purpose = self::intArg($frame->calledArgs[1], 2, 'purpose');
        $caInfo = [];
        if ($argc >= 3) {
            $caInfo = self::stringListArg($frame->calledArgs[2], 3, 'ca_info');
        }
        $untrusted = null;
        if (4 === $argc) {
            $untrusted = self::nullableStringArg($frame->calledArgs[3], 4, 'untrusted_certificates_file');
        }

        $frame->returnVar->copyFrom(
            VmOpensslObjects::checkPurpose(
                $frame->vmContext,
                $frame->calledArgs[0],
                $purpose,
                $caInfo,
                $untrusted,
                $frame
            )
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(
                'openssl_x509_checkpurpose() expects at least 2 arguments, '.$argc.' given'
            );
        }

        return JitOpensslX509::checkPurpose(
            $context,
            $args[0],
            $args[1],
            $args[2] ?? null,
            $args[3] ?? null
        );
    }

    private static function intArg(Variable $arg, int $position, string $paramName): int
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_INTEGER === $arg->type) {
            return $arg->toInt();
        }
        if (Variable::TYPE_DOUBLE === $arg->type) {
            return (int) $arg->toFloat();
        }
        if (Variable::TYPE_BOOLEAN === $arg->type) {
            return $arg->toBool() ? 1 : 0;
        }
        if (Variable::TYPE_NULL === $arg->type) {
            return 0;
        }
        if (Variable::TYPE_STRING === $arg->type) {
            return (int) $arg->toString();
        }

        throw new \TypeError(\sprintf(
            'openssl_x509_checkpurpose(): Argument #%d ($%s) must be of type int, %s given',
            $position,
            $paramName,
            match ($arg->type) {
                Variable::TYPE_ARRAY => 'array',
                Variable::TYPE_OBJECT => $arg->toObject()->class->name,
                default => 'mixed',
            }
        ));
    }

    /**
     * @return list<string>
     */
    private static function stringListArg(Variable $arg, int $position, string $paramName): array
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $arg->type) {
            throw new \TypeError(\sprintf(
                'openssl_x509_checkpurpose(): Argument #%d ($%s) must be of type array, %s given',
                $position,
                $paramName,
                match ($arg->type) {
                    Variable::TYPE_NULL => 'null',
                    Variable::TYPE_STRING => 'string',
                    Variable::TYPE_INTEGER => 'int',
                    Variable::TYPE_OBJECT => $arg->toObject()->class->name,
                    default => 'mixed',
                }
            ));
        }

        $out = [];
        foreach ($arg->toArray()->iterateKeyed(true) as [, $valueVar]) {
            $valueVar = $valueVar->resolveIndirect();
            if (Variable::TYPE_STRING === $valueVar->type) {
                $out[] = $valueVar->toString();
            } elseif (Variable::TYPE_INTEGER === $valueVar->type) {
                $out[] = (string) $valueVar->toInt();
            } elseif (Variable::TYPE_DOUBLE === $valueVar->type) {
                $out[] = (string) $valueVar->toFloat();
            } elseif (Variable::TYPE_BOOLEAN === $valueVar->type) {
                $out[] = $valueVar->toBool() ? '1' : '';
            } elseif (Variable::TYPE_NULL === $valueVar->type) {
                $out[] = '';
            }
        }

        return $out;
    }

    private static function nullableStringArg(Variable $arg, int $position, string $paramName): ?string
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_NULL === $arg->type) {
            return null;
        }
        if (Variable::TYPE_STRING === $arg->type) {
            return $arg->toString();
        }
        if (Variable::TYPE_INTEGER === $arg->type) {
            return (string) $arg->toInt();
        }
        if (Variable::TYPE_DOUBLE === $arg->type) {
            return (string) $arg->toFloat();
        }
        if (Variable::TYPE_BOOLEAN === $arg->type) {
            return $arg->toBool() ? '1' : '';
        }

        throw new \TypeError(\sprintf(
            'openssl_x509_checkpurpose(): Argument #%d ($%s) must be of type ?string, %s given',
            $position,
            $paramName,
            match ($arg->type) {
                Variable::TYPE_ARRAY => 'array',
                Variable::TYPE_OBJECT => $arg->toObject()->class->name,
                default => 'mixed',
            }
        ));
    }
}
