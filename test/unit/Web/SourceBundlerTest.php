<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Web\DeployRoot;
use PHPCompiler\Web\SourceBundler;

/**
 * AOT bundle must not leave top-level return from config includes (issue #452, #485).
 */
final class SourceBundlerTest extends TestCase
{
    public function testReturnOnlyConfigIncludeBecomesAssignment(): void
    {
        $dir = sys_get_temp_dir().'/phpc_bundle_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        $this->assertTrue(mkdir($dir.'/public', 0777, true));
        try {
            file_put_contents(
                $dir.'/config.php',
                "<?php\ndeclare(strict_types=1);\n\nreturn ['app_name' => 'TestApp'];\n"
            );
            file_put_contents(
                $dir.'/public/index.php',
                "<?php\n\$config = require __DIR__ . '/../config.php';\necho \$config['app_name'];\n"
            );

            [$bundled] = SourceBundler::bundleForAot(
                $dir.'/public/index.php',
                [realpath($dir.'/config.php') ?: $dir.'/config.php']
            );

            $this->assertStringNotContainsString('return [', $bundled);
            $this->assertStringContainsString("\$config = ['app_name' => 'TestApp'];", $bundled);
            $this->assertStringContainsString("echo \$config['app_name'];", $bundled);
        } finally {
            @unlink($dir.'/config.php');
            @unlink($dir.'/public/index.php');
            @rmdir($dir.'/public');
            @rmdir($dir);
        }
    }

    public function testMethodLevelLiteralIncludeIsNotBundled(): void
    {
        $entry = realpath(dirname(__DIR__, 2).'/fixtures/aot/cases/method_include_void/entry.php');
        $this->assertNotFalse($entry);
        $runtime = new \PHPCompiler\Runtime(\PHPCompiler\Runtime::MODE_AOT);
        $includes = \PHPCompiler\Web\LiteralIncludeDiscovery::discoverDirectAbsolutePaths($runtime, $entry);
        $this->assertSame([], $includes, 'template includes inside methods must stay JIT-inlined, not prepended at file scope');

        [$bundled] = SourceBundler::bundleForAot($entry, $includes, null);
        $this->assertStringNotContainsString("echo \$title", $bundled);
        $this->assertStringContainsString("include __DIR__", $bundled);
    }

    public function testRewriteDirConstantSkipsStringLiterals(): void
    {
        $dir = sys_get_temp_dir().'/phpc_bundle_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        $this->assertTrue(mkdir($dir.'/public', 0777, true));
        try {
            file_put_contents(
                $dir.'/lib.php',
                "<?php\ndeclare(strict_types=1);\n\n"
                ."\$path = __DIR__ . '/x.php';\n"
                ."\$err = '__DIR__/__FILE__ used without script context';\n"
                ."\$err2 = \"__DIR__/__FILE__ used without script context\";\n"
            );
            file_put_contents(
                $dir.'/public/index.php',
                "<?php\nrequire __DIR__ . '/../lib.php';\n"
            );

            [$bundled] = SourceBundler::bundleForAot(
                $dir.'/public/index.php',
                [realpath($dir.'/lib.php') ?: $dir.'/lib.php']
            );

            $this->assertStringContainsString(
                "'__DIR__/__FILE__ used without script context'",
                $bundled
            );
            $this->assertStringContainsString(
                '"__DIR__/__FILE__ used without script context"',
                $bundled
            );
            $this->assertStringNotContainsString('$path = __DIR__', $bundled);
            $this->assertStringContainsString("\$path = ", $bundled);
            $this->assertStringContainsString("'/x.php'", $bundled);
        } finally {
            @unlink($dir.'/lib.php');
            @unlink($dir.'/public/index.php');
            @rmdir($dir.'/public');
            @rmdir($dir);
        }
    }

    public function testBundleEmitsSingleStrictTypesDeclare(): void
    {
        $dir = sys_get_temp_dir().'/phpc_bundle_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        $this->assertTrue(mkdir($dir.'/public', 0777, true));
        try {
            file_put_contents(
                $dir.'/lib.php',
                "<?php\ndeclare(strict_types=1);\n\nfunction lib_fn(): int { return 1; }\n"
            );
            file_put_contents(
                $dir.'/public/index.php',
                "<?php\ndeclare(strict_types=1);\n\nrequire __DIR__ . '/../lib.php';\necho lib_fn();\n"
            );

            [$bundled] = SourceBundler::bundleForAot(
                $dir.'/public/index.php',
                [realpath($dir.'/lib.php') ?: $dir.'/lib.php']
            );

            $this->assertSame(1, substr_count($bundled, 'declare(strict_types=1);'));
        } finally {
            @unlink($dir.'/lib.php');
            @unlink($dir.'/public/index.php');
            @rmdir($dir.'/public');
            @rmdir($dir);
        }
    }

    public function testBundleWrapsDocblockNamespacedWordBeforeSemicolonNamespace(): void
    {
        $dir = sys_get_temp_dir().'/phpc_bundle_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        try {
            file_put_contents(
                $dir.'/main.php',
                "<?php\n\n"
                ."/**\n"
                ." * Phase D: link namespaced lib/OpCode.php (issue #540).\n"
                ." * Bundles lib/OpCode.php via literal require_once.\n"
                ." */\n\n"
                ."namespace PHPCompiler;\n\n"
                ."echo \"ok\\n\";\n"
            );

            [$bundled] = SourceBundler::bundleForAot($dir.'/main.php', []);

            $this->assertStringContainsString('link namespaced lib/OpCode.php', $bundled);
            $this->assertStringContainsString('namespace PHPCompiler {', $bundled);
            $this->assertStringNotContainsString('link namespace PHPCompiler {', $bundled);
        } finally {
            @unlink($dir.'/main.php');
            @rmdir($dir);
        }
    }

    public function testBundleWrapsRepeatedUseImportsInBracedNamespaces(): void
    {
        $dir = sys_get_temp_dir().'/phpc_bundle_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        try {
            file_put_contents(
                $dir.'/block.php',
                "<?php\nnamespace PHPCompiler;\n\nuse PHPCompiler\\VM\\Context;\n\nclass Block {}\n"
            );
            file_put_contents(
                $dir.'/frame.php',
                "<?php\n# generated\nnamespace PHPCompiler;\n\nuse PHPCompiler\\VM\\Context;\n\nclass Frame {}\n"
            );
            file_put_contents(
                $dir.'/native.php',
                "<?php\n# generated\nnamespace PHPCompiler\\JIT\\Call;\n\nuse PHPCompiler\\JIT\\Context;\n\nclass Native {}\n"
            );
            file_put_contents(
                $dir.'/index.php',
                "<?php\nrequire __DIR__ . '/block.php';\nrequire __DIR__ . '/frame.php';\nrequire __DIR__ . '/native.php';\necho 'ok';\n"
            );

            [$bundled] = SourceBundler::bundleForAot(
                $dir.'/index.php',
                [
                    realpath($dir.'/block.php') ?: $dir.'/block.php',
                    realpath($dir.'/frame.php') ?: $dir.'/frame.php',
                    realpath($dir.'/native.php') ?: $dir.'/native.php',
                ]
            );

            $this->assertStringContainsString('namespace PHPCompiler {', $bundled);
            $this->assertStringContainsString('namespace PHPCompiler\\JIT\\Call {', $bundled);
            $this->assertStringNotContainsString("namespace PHPCompiler;\n\nuse", $bundled);
            $this->assertStringContainsString('namespace {', $bundled);
        } finally {
            foreach (['block.php', 'frame.php', 'native.php', 'index.php'] as $file) {
                @unlink($dir.'/'.$file);
            }
            @rmdir($dir);
        }
    }

    public function testBundleStripsRequiresFromIncludedBodies(): void
    {
        $dir = sys_get_temp_dir().'/phpc_bundle_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        try {
            file_put_contents(
                $dir.'/dep.php',
                "<?php\nnamespace PHPCompiler;\n\nclass Dep {}\n"
            );
            file_put_contents(
                $dir.'/lib.php',
                "<?php\nnamespace PHPCompiler;\n\nrequire_once __DIR__ . '/dep.php';\n\nclass Lib {}\n"
            );
            file_put_contents(
                $dir.'/index.php',
                "<?php\nrequire __DIR__ . '/lib.php';\n"
            );

            [$bundled] = SourceBundler::bundleForAot(
                $dir.'/index.php',
                [
                    realpath($dir.'/dep.php') ?: $dir.'/dep.php',
                    realpath($dir.'/lib.php') ?: $dir.'/lib.php',
                ]
            );

            $this->assertStringNotContainsString("require_once __DIR__ . '/dep.php'", $bundled);
            $this->assertStringContainsString('class Lib', $bundled);
        } finally {
            foreach (['dep.php', 'lib.php', 'index.php'] as $file) {
                @unlink($dir.'/'.$file);
            }
            @rmdir($dir);
        }
    }

    public function testBundleRenamesConflictingUseImportLocalNames(): void
    {
        $dir = sys_get_temp_dir().'/phpc_bundle_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        try {
            file_put_contents(
                $dir.'/types.php',
                "<?php\nnamespace PHPCompiler\\JIT;\n\nuse PHPTypes\\Type;\n\nclass TypesUser {}\n"
            );
            file_put_contents(
                $dir.'/llvm.php',
                "<?php\nnamespace PHPCompiler\\JIT;\n\nuse PHPLLVM\\Type;\n\nclass LlvmUser {\n"
                ."    public static function entry(Type \$type): void {}\n}\n"
            );
            file_put_contents(
                $dir.'/index.php',
                "<?php\nrequire __DIR__ . '/types.php';\nrequire __DIR__ . '/llvm.php';\n"
            );

            [$bundled] = SourceBundler::bundleForAot(
                $dir.'/index.php',
                [
                    realpath($dir.'/types.php') ?: $dir.'/types.php',
                    realpath($dir.'/llvm.php') ?: $dir.'/llvm.php',
                ]
            );

            $this->assertStringContainsString('use PHPTypes\\Type;', $bundled);
            $this->assertStringContainsString('use PHPLLVM\\Type as PhpllvmType;', $bundled);
            $this->assertStringContainsString('entry(PhpllvmType $type)', $bundled);
            $this->assertStringNotContainsString('use PHPLLVM\\Type;', $bundled);
        } finally {
            foreach (['types.php', 'llvm.php', 'index.php'] as $file) {
                @unlink($dir.'/'.$file);
            }
            @rmdir($dir);
        }
    }

    public function testBundleSkipsDuplicateUseImportAliasCasing(): void
    {
        $dir = sys_get_temp_dir().'/phpc_bundle_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        try {
            file_put_contents(
                $dir.'/first.php',
                "<?php\nnamespace PHPCompiler\\JIT;\n\nuse PHPCompiler\\VM\\Variable as VMVariable;\n\nclass First {}\n"
            );
            file_put_contents(
                $dir.'/second.php',
                "<?php\nnamespace PHPCompiler\\JIT;\n\nuse PHPCompiler\\VM\\Variable as VmVariable;\n\nclass Second {}\n"
            );
            file_put_contents(
                $dir.'/index.php',
                "<?php\nrequire __DIR__ . '/first.php';\nrequire __DIR__ . '/second.php';\n"
            );

            [$bundled] = SourceBundler::bundleForAot(
                $dir.'/index.php',
                [
                    realpath($dir.'/first.php') ?: $dir.'/first.php',
                    realpath($dir.'/second.php') ?: $dir.'/second.php',
                ]
            );

            $this->assertSame(1, substr_count($bundled, 'use PHPCompiler\\VM\\Variable as'));
        } finally {
            foreach (['first.php', 'second.php', 'index.php'] as $file) {
                @unlink($dir.'/'.$file);
            }
            @rmdir($dir);
        }
    }

    public function testBundleAliasesUseImportThatShadowsDeclaredType(): void
    {
        $dir = sys_get_temp_dir().'/phpc_bundle_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        try {
            file_put_contents(
                $dir.'/variable.php',
                "<?php\nnamespace PHPCompiler\\JIT;\n\nclass Variable {}\n"
            );
            file_put_contents(
                $dir.'/operand.php',
                "<?php\nnamespace PHPCompiler\\JIT;\n\nuse PHPCompiler\\VM\\Variable;\n\nclass Operand {\n"
                ."    public static function check(): bool {\n"
                ."        return Variable::TYPE_STRING === Variable::TYPE_STRING;\n"
                ."    }\n}\n"
            );
            file_put_contents(
                $dir.'/call.php',
                "<?php\nnamespace PHPCompiler\\JIT;\n\ninterface Call {\n"
                ."    public function call(Variable ...\$args): void;\n}\n"
            );
            file_put_contents(
                $dir.'/index.php',
                "<?php\nrequire __DIR__ . '/variable.php';\nrequire __DIR__ . '/operand.php';\nrequire __DIR__ . '/call.php';\n"
            );

            [$bundled] = SourceBundler::bundleForAot(
                $dir.'/index.php',
                [
                    realpath($dir.'/variable.php') ?: $dir.'/variable.php',
                    realpath($dir.'/operand.php') ?: $dir.'/operand.php',
                    realpath($dir.'/call.php') ?: $dir.'/call.php',
                ]
            );

            $this->assertStringContainsString('use PHPCompiler\\VM\\Variable as VmVariable;', $bundled);
            $this->assertStringContainsString('Variable::TYPE_STRING', $bundled);
            $this->assertStringContainsString('VmVariable::TYPE_STRING', $bundled);
            $this->assertStringContainsString('function call(Variable ...$args)', $bundled);
        } finally {
            foreach (['variable.php', 'operand.php', 'call.php', 'index.php'] as $file) {
                @unlink($dir.'/'.$file);
            }
            @rmdir($dir);
        }
    }

    public function testBundleMiniWebAppUsesLiteralDirForMethodIncludes(): void
    {
        $entry = realpath(dirname(__DIR__, 3).'/examples/003-MiniWebApp/public/index.php');
        $this->assertNotFalse($entry);
        $runtime = new \PHPCompiler\Runtime(\PHPCompiler\Runtime::MODE_AOT);
        $includes = \PHPCompiler\Web\LiteralIncludeDiscovery::discoverDirectAbsolutePaths($runtime, $entry);
        $root = DeployRoot::findProjectRootForPath($entry);
        $this->assertNotNull($root);
        [$bundled] = SourceBundler::bundleForAot($entry, $includes, $root);

        $this->assertStringNotContainsString('phpc_deploy_path(', $bundled);
        $this->assertStringContainsString('/templates/layout.php', $bundled);
        $this->assertStringContainsString(
            var_export(realpath($root.'/src') ?: $root.'/src', true),
            $bundled
        );
    }

    public function testMapBundledLineResolvesOriginalFileAndLine(): void
    {
        $tmp = sys_get_temp_dir().'/phpc_bundle_map_'.uniqid('', true);
        mkdir($tmp);
        $helper = $tmp.'/helper.php';
        $entry = $tmp.'/main.php';
        file_put_contents($helper, "<?php\nfunction helper_fn() {}\n");
        file_put_contents($entry, "<?php\nrequire 'helper.php';\nhelper_fn();\n");
        try {
            [$bundled] = SourceBundler::bundleForAot($entry, [$helper], null);
            $mapped = SourceBundler::mapBundledLine($bundled, 14);
            $this->assertNotNull($mapped);
            $this->assertSame(realpath($entry), $mapped[0]);
            $this->assertSame(3, $mapped[1]);
        } finally {
            @unlink($helper);
            @unlink($entry);
            @rmdir($tmp);
        }
    }
}
