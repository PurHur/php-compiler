<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

final class ReflectionConstantRegistrationTest extends TestCase
{
    public function testReflectionConstantAbsentOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::advertisesReflectionConstantClass());

        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(class_exists('ReflectionConstant', false));
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'reflection_constant_absent.php'));
        $out = ob_get_clean();

        $this->assertSame('false', trim($out));
    }

    public function testReflectionConstantRegisteredOnForwardProfile83(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::advertisesReflectionConstantClass());

            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
var_export(class_exists('ReflectionConstant', false));
echo "\n";
define('FOO_RC_25504', 99);
$ref = new ReflectionConstant('FOO_RC_25504');
echo $ref->getName(), '=', $ref->getValue(), "\n";
PHP;
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'reflection_constant_exists_83.php'));
            $out = ob_get_clean();

            $this->assertSame("true\nFOO_RC_25504=99", trim($out));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testReflectionConstantRegisteredOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::advertisesReflectionConstantClass());

            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
var_export(class_exists('ReflectionConstant', false));
PHP;
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'reflection_constant_exists.php'));
            $out = ob_get_clean();

            $this->assertSame('true', trim($out));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testReflectionConstantOmitsClassConstantApisOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
$c = new ReflectionConstant('PHP_VERSION');
foreach ([
    'getDeclaringClass', 'getModifiers', 'getType', 'hasType',
    'isEnumCase', 'isFinal', 'isPrivate', 'isProtected', 'isPublic',
    'getDeprecatedMessage', 'getDeprecatedVersion',
] as $m) {
    echo $m, '=', method_exists($c, $m) ? '1' : '0', "\n";
}
class C28156u { public const X = 1; }
$rcc = new ReflectionClassConstant(C28156u::class, 'X');
echo 'rcc_getDeclaringClass=', method_exists($rcc, 'getDeclaringClass') ? '1' : '0', "\n";
echo 'isDeprecated=', method_exists($c, 'isDeprecated') ? '1' : '0', "\n";
echo 'IS_PUBLIC=', defined('ReflectionConstant::IS_PUBLIC') ? '1' : '0', "\n";
PHP;
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'reflection_constant_phantoms_28156.php'));
            $out = ob_get_clean();

            $this->assertSame(
                "getDeclaringClass=0\ngetModifiers=0\ngetType=0\nhasType=0\nisEnumCase=0\nisFinal=0\nisPrivate=0\nisProtected=0\nisPublic=0\ngetDeprecatedMessage=0\ngetDeprecatedVersion=0\nrcc_getDeclaringClass=1\nisDeprecated=1\nIS_PUBLIC=0",
                trim($out)
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testReflectionConstantGetAttributesAbsentOnProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertFalse(CompilerVersion::advertisesReflectionConstantGetAttributes());

            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
$c = new ReflectionConstant('PHP_VERSION');
echo method_exists($c, 'getAttributes') ? '1' : '0', "\n";
class C28157 { public const X = 1; }
$rcc = new ReflectionClassConstant(C28157::class, 'X');
echo method_exists($rcc, 'getAttributes') ? '1' : '0', "\n";
PHP;
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'reflection_constant_getattributes_84.php'));
            $out = ob_get_clean();

            $this->assertSame("0\n1", trim($out));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testReflectionConstantGetAttributesPresentOnProfile85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->assertTrue(CompilerVersion::advertisesReflectionConstantGetAttributes());

            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
#[\Attribute]
class Attr28157 {}
#[Attr28157]
const C28157_ATTR = 1;
$c = new ReflectionConstant('C28157_ATTR');
echo method_exists($c, 'getAttributes') ? '1' : '0', "\n";
$attrs = $c->getAttributes();
echo count($attrs), ':', $attrs[0]->getName(), "\n";
$plain = new ReflectionConstant('PHP_VERSION');
echo count($plain->getAttributes()), "\n";
PHP;
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'reflection_constant_getattributes_85.php'));
            $out = ob_get_clean();

            $this->assertSame("1\n1:Attr28157\n0", trim($out));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
