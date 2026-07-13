<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmVarFormat;
use PHPCompiler\VM\ResourceState;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** debug_zval_dump() resource refcount parity (#18419). */
final class DebugZvalDumpResourceRefcountTest extends TestCase
{
    public function testResourceDebugRefcountMatchesZendSingleReference(): void
    {
        $runtime = new \PHPCompiler\Runtime();
        $ctx = $runtime->vmContext;
        $vm = new \PHPCompiler\VM($ctx);
        $var = new Variable();
        ResourceSupport::wrap($var, 42, ResourceState::KIND_STREAM, $ctx);
        $ctx->ensureGlobal('h')->object($var->toObject());

        $line = VmVarFormat::tryFormatDebugZvalDump($vm, $var);
        $this->assertNotNull($line);
        $this->assertMatchesRegularExpression('/refcount\(2\)$/', trim($line));
    }

    public function testResourceDebugRefcountMatchesZendWithAlias(): void
    {
        $runtime = new \PHPCompiler\Runtime();
        $ctx = $runtime->vmContext;
        $vm = new \PHPCompiler\VM($ctx);
        $var = new Variable();
        ResourceSupport::wrap($var, 42, ResourceState::KIND_STREAM, $ctx);
        $ctx->ensureGlobal('h')->object($var->toObject());
        $ctx->ensureGlobal('h2')->object($var->toObject());

        $line = VmVarFormat::tryFormatDebugZvalDump($vm, $var);
        $this->assertNotNull($line);
        $this->assertMatchesRegularExpression('/refcount\(3\)$/', trim($line));
    }
}
