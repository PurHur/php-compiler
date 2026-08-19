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
 * openssl_x509_parse() — X.509 metadata array (php-src ext/openssl/xp.c; #6274 VM, JIT/AOT #32496).
 */
final class openssl_x509_parse extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_x509_parse');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'openssl_x509_parse() expects at least 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $shortnames = true;
        if (2 === $argc) {
            $shortnames = self::boolArg($frame->calledArgs[1], 2);
        }
        $frame->returnVar->copyFrom(
            VmOpensslObjects::parseCertificate($frame->vmContext, $frame->calledArgs[0], $shortnames)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'openssl_x509_parse() expects at least 1 argument, '.$argc.' given'
            );
        }

        return JitOpensslX509::parse($context, $args[0], $args[1] ?? null);
    }

    private static function boolArg(Variable $arg, int $position): bool
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_BOOLEAN === $arg->type) {
            return $arg->toBool();
        }
        if (Variable::TYPE_NULL === $arg->type) {
            return false;
        }
        if (Variable::TYPE_INTEGER === $arg->type) {
            return 0 !== $arg->toInt();
        }

        return (bool) $arg->toString();
    }
}
