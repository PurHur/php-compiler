<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile verify for date_add/date_sub/date_modify/date_diff (#4604 phase 2).
 *
 * @group llvm
 * @runInSeparateProcess
 * @preserveGlobalState disabled
 */
final class DateMutationJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — date mutation JIT compile test needs LLVM (#4604)');
        }
    }

    public function testDateMutationBuiltinsModuleVerify(): void
    {
        $code = <<<'PHP'
<?php
$dt = new DateTime('2026-06-01 12:00:00', new DateTimeZone('UTC'));
$interval = new DateInterval('P1D');
date_add($dt, $interval);

$dt2 = new DateTime('2026-06-01', new DateTimeZone('UTC'));
date_modify($dt2, '+2 days');

$a = new DateTime('2026-06-01', new DateTimeZone('UTC'));
$b = new DateTime('2026-06-03', new DateTimeZone('UTC'));
$diff = date_diff($a, $b);

$dt3 = new DateTime('2026-06-03', new DateTimeZone('UTC'));
$interval2 = new DateInterval('P1D');
$interval2->invert = 1;
date_sub($dt3, $interval2);
echo "ok\n";
PHP;

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'date_mutation_jit.php');
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $bc = $context->module->printToString();
        $this->assertStringNotContainsString(
            'not implemented for JIT',
            $bc,
            'date mutation builtins should lower without JIT stubs (#4604)'
        );
        $this->assertStringContainsString(
            '__phpc_date_apply_interval',
            $bc,
            'expected date_add/date_sub interval LLVM runtime (#4604)'
        );
        $this->addToAssertionCount(1);
    }

    public function testDateTimeAddSubMethodModuleVerify(): void
    {
        $code = <<<'PHP'
<?php
$dt = new DateTime('2020-01-15', new DateTimeZone('UTC'));
$dt->add(new DateInterval('P1D'));
$dt->sub(new DateInterval('P1D'));
$imm = new DateTimeImmutable('2020-01-15', new DateTimeZone('UTC'));
$imm2 = $imm->add(new DateInterval('P1D'));
echo $dt->format('Y-m-d'), ',', $imm->format('Y-m-d'), ',', $imm2->format('Y-m-d'), "\n";
PHP;

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'datetime_add_sub_jit.php');
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $bc = $context->module->printToString();
        $this->assertStringNotContainsString(
            'not implemented for JIT',
            $bc,
            'DateTime::add/sub should lower without JIT stubs (#30760)'
        );
        $this->assertStringNotContainsString(
            'Unable to lookup non-existing function',
            $bc,
            'DateTime::add/sub must not emit missing-helper lookups (#30760)'
        );
        $this->addToAssertionCount(1);
    }
}
