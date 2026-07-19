<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #20727 */
final class AttributeTargetProfileTest extends TestCase
{
    private ?string $prevProfile = null;

    protected function setUp(): void
    {
        $raw = getenv('PHP_COMPILER_PROFILE');
        $this->prevProfile = false === $raw ? null : $raw;
    }

    protected function tearDown(): void
    {
        if (null === $this->prevProfile) {
            putenv('PHP_COMPILER_PROFILE');
            unset($_ENV['PHP_COMPILER_PROFILE'], $_SERVER['PHP_COMPILER_PROFILE']);
        } else {
            putenv('PHP_COMPILER_PROFILE='.$this->prevProfile);
            $_ENV['PHP_COMPILER_PROFILE'] = $this->prevProfile;
            $_SERVER['PHP_COMPILER_PROFILE'] = $this->prevProfile;
        }
    }

    public function testReferenceProfileTargetAllMatchesReflection(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $_SERVER['PHP_COMPILER_PROFILE'] = '8.4';
        $this->assertFalse(CompilerVersion::supportsAttributeTargetConstant());

        $out = $this->runVm(<<<'PHP'
<?php
$r = (new ReflectionClass(Attribute::class))->getConstant('TARGET_ALL');
$d = Attribute::TARGET_ALL;
$tc = (new ReflectionClass(Attribute::class))->hasConstant('TARGET_CONSTANT');
$repR = (new ReflectionClass(Attribute::class))->getConstant('IS_REPEATABLE');
$repD = Attribute::IS_REPEATABLE;
echo "$r $d ".($tc ? '1' : '0')." $repR $repD ".($r === $d && $repR === $repD ? 'yes' : 'NO');
PHP);

        $this->assertSame('63 63 0 64 64 yes', $out);
    }

    public function testForward85TargetConstantMatchesReflection(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.5');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.5';
        $_SERVER['PHP_COMPILER_PROFILE'] = '8.5';
        $this->assertTrue(CompilerVersion::supportsAttributeTargetConstant());

        $out = $this->runVm(<<<'PHP'
<?php
$rc = new ReflectionClass(Attribute::class);
echo $rc->getConstant('TARGET_ALL'), ' ', Attribute::TARGET_ALL, ' ';
echo $rc->hasConstant('TARGET_CONSTANT') ? '1' : '0', ' ';
echo $rc->getConstant('TARGET_CONSTANT'), ' ', Attribute::TARGET_CONSTANT, ' ';
echo $rc->getConstant('IS_REPEATABLE'), ' ', Attribute::IS_REPEATABLE, ' ';
echo (
    $rc->getConstant('TARGET_ALL') === Attribute::TARGET_ALL
    && $rc->getConstant('TARGET_CONSTANT') === Attribute::TARGET_CONSTANT
    && $rc->getConstant('IS_REPEATABLE') === Attribute::IS_REPEATABLE
) ? 'yes' : 'NO';
PHP);

        $this->assertSame('127 127 1 64 64 128 128 yes', $out);
    }

    private function runVm(string $code): string
    {
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'attribute_target_profile.php'));

        return trim((string) ob_get_clean());
    }
}
