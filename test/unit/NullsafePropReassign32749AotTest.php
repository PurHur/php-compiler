<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT nullsafe ?-> on a local reassigned after an earlier nullsafe fetch — #32749.
 *
 * @group llvm
 */
final class NullsafePropReassign32749AotTest extends TestCase
{
    public function testReproSourceExists(): void
    {
        $path = realpath(__DIR__.'/../../test/repro/nullsafe_prop_reassign.php');
        $this->assertNotFalse($path);
        $this->assertFileExists($path);
    }

    public function testAotFixtureCatalogIncludesRegression(): void
    {
        $fixture = realpath(__DIR__.'/../fixtures/aot/cases/nullsafe_prop_reassign_32749.phpt');
        $this->assertNotFalse($fixture);
        $this->assertFileExists($fixture);
    }
}
