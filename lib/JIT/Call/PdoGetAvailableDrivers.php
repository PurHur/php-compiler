<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * PDO::getAvailableDrivers() — thin AOT (#27619, #30994).
 *
 * Dispatch via {@see Context::$extensionLowering} so lib/JIT does not import
 * {@code ext\pdo} (#36204). php-src: ext/pdo/pdo.c — zim_PDO_getAvailableDrivers
 * Shares the driver list with {@see \PHPCompiler\ext\pdo\pdo_drivers}.
 * Static — no implicit $this (peer DateTimeZone::listAbbreviations / #30898).
 */
final class PdoGetAvailableDrivers implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name = 'PDO::getAvailableDrivers';

    /** @var list<string> php-src pdo.stub.php — zero-arg static. */
    public array $paramNames = [];

    /** Static method — no implicit $this. */
    public int $namedArgsReceiverPrefix = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requirePdo()->getAvailableDrivers($context, ...$args);
    }
}
