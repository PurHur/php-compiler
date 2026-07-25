<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\ModuleRegistry;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\ext\uuid\UuidConstants;
use PHPUnit\Framework\TestCase;

/**
 * uuid module registration (issue #5910 / #22228).
 *
 * @group uuid_module
 */
final class UuidModuleTest extends TestCase
{
    public function test_uuid_module_registration(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        self::assertTrue(VmReflection::functionExists($ctx, 'uuid_create'));
        self::assertTrue(VmReflection::functionExists($ctx, 'uuid_generate'));
        self::assertTrue(VmReflection::functionExists($ctx, 'uuid_is_valid'));
        self::assertTrue(VmReflection::functionExists($ctx, 'uuid_parse'));
        self::assertTrue(VmReflection::functionExists($ctx, 'uuid_unparse'));
        self::assertTrue(VmReflection::functionExists($ctx, 'uuid_compare'));
        self::assertTrue(ModuleRegistry::extensionLoaded('uuid'));

        $code = <<<'PHP'
<?php
echo (int) defined('UUID_TYPE_RANDOM');
echo (int) function_exists('uuid_create');
echo UUID_TYPE_RANDOM;
$id = uuid_create(UUID_TYPE_RANDOM);
echo (int) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id);
PHP;
        $block = $runtime->parseAndCompile($code, 'uuid_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('1141', ob_get_clean());
        self::assertSame(4, UuidConstants::UUID_TYPE_RANDOM);
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
