<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\ModuleRegistry;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\ext\uuid\UuidConstants;
use PHPCompiler\ext\uuid\UuidExtensionPolicy;
use PHPUnit\Framework\TestCase;

/**
 * uuid module registration (issue #5910 / #22228 / #23962).
 *
 * @group uuid_module
 */
final class UuidModuleTest extends TestCase
{
    private string|false $savedProfile = false;

    protected function setUp(): void
    {
        $this->savedProfile = getenv('PHP_COMPILER_PROFILE');
        // Functional uuid surface needs forward profile when host lacks pecl-uuid (#23962).
        if (!UuidExtensionPolicy::advertisesExtension()) {
            putenv('PHP_COMPILER_PROFILE=8.4');
            $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        }
    }

    protected function tearDown(): void
    {
        if (false === $this->savedProfile || null === $this->savedProfile) {
            putenv('PHP_COMPILER_PROFILE');
            unset($_ENV['PHP_COMPILER_PROFILE']);
        } else {
            putenv('PHP_COMPILER_PROFILE='.$this->savedProfile);
            $_ENV['PHP_COMPILER_PROFILE'] = $this->savedProfile;
        }
    }

    public function test_uuid_module_registration(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        self::assertTrue(VmReflection::functionExists($ctx, 'uuid_create'));
        self::assertTrue(VmReflection::functionExists($ctx, 'uuid_generate'));
        self::assertTrue(VmReflection::functionExists($ctx, 'uuid_generate_md5'));
        self::assertTrue(VmReflection::functionExists($ctx, 'uuid_generate_sha1'));
        self::assertTrue(VmReflection::functionExists($ctx, 'uuid_is_valid'));
        self::assertTrue(VmReflection::functionExists($ctx, 'uuid_parse'));
        self::assertTrue(VmReflection::functionExists($ctx, 'uuid_unparse'));
        self::assertTrue(VmReflection::functionExists($ctx, 'uuid_compare'));
        self::assertTrue(ModuleRegistry::extensionLoaded('uuid'));

        $code = <<<'PHP'
<?php
echo (int) defined('UUID_TYPE_RANDOM');
echo (int) function_exists('uuid_create');
echo (int) function_exists('uuid_generate_md5');
echo (int) function_exists('uuid_generate_sha1');
echo UUID_TYPE_RANDOM;
$id = uuid_create(UUID_TYPE_RANDOM);
echo (int) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id);
$ns = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
echo uuid_generate_md5($ns, 'php.net');
PHP;
        $block = $runtime->parseAndCompile($code, 'uuid_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('11114111a38b9a-b3da-360f-9353-a5a725514269', ob_get_clean());
        self::assertSame(4, UuidConstants::UUID_TYPE_RANDOM);
    }

    public function test_uuid_generate_md5_sha1_named_args_and_reflection(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$ns = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
echo uuid_generate_sha1(uuid_ns: $ns, name: 'php.net'), "\n";
$rf = new ReflectionFunction('uuid_generate_sha1');
echo $rf->getParameters()[0]->getName(), ',', $rf->getParameters()[1]->getName(), "\n";
echo (string) $rf->getReturnType(), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'uuid_md5_sha1.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "c4a760a8-dbcf-5254-a0d9-6a4474bd1b62\nuuid_ns,name\nstring\n",
            ob_get_clean()
        );
    }

    public function test_uuid_parse_unparse_roundtrip(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$id = '550e8400-e29b-41d4-a716-446655440000';
echo (int) uuid_is_valid($id);
echo uuid_unparse(uuid_parse($id));
echo uuid_type($id);
echo uuid_variant($id);
echo (int) uuid_is_null('00000000-0000-0000-0000-000000000000');
echo UUID_TYPE_NULL;
PHP;
        $block = $runtime->parseAndCompile($code, 'uuid_surface.php');
        ob_start();
        $runtime->run($block);
        // type=4 (random), variant=1 (DCE), is_null=1, UUID_TYPE_NULL=-1
        self::assertSame('1550e8400-e29b-41d4-a716-446655440000411-1', ob_get_clean());
    }
}
