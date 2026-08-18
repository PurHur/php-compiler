<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * posix_sysconf/pathconf/fpathconf Reflection + named args (#27918, ext/posix/posix.stub.php).
 */
final class PosixSysconfReflectionTest extends TestCase
{
    public function testArginfoMatchesPhpSrcStub(): void
    {
        $this->assertSame(['conf_id'], BuiltinParamNames::forFunction('posix_sysconf'));
        $this->assertSame(['path', 'name'], BuiltinParamNames::forFunction('posix_pathconf'));
        $this->assertSame(['file_descriptor', 'name'], BuiltinParamNames::forFunction('posix_fpathconf'));
        $this->assertSame(1, BuiltinParamNames::paramCountForInternalFunction('posix_sysconf'));
        $this->assertSame(2, BuiltinParamNames::paramCountForInternalFunction('posix_pathconf'));
        $this->assertSame(2, BuiltinParamNames::paramCountForInternalFunction('posix_fpathconf'));
        $this->assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction('posix_sysconf'));
        $this->assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('posix_pathconf'));
        $this->assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction('posix_fpathconf'));

        $this->assertSame('int', BuiltinInternalArgInfo::returnTypeLabelForFunction('posix_sysconf'));
        $this->assertSame('int|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('posix_pathconf'));
        $this->assertSame('int|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('posix_fpathconf'));

        $sys = BuiltinInternalArgInfo::paramInfoForFunction('posix_sysconf', 0);
        $this->assertNotNull($sys);
        $this->assertSame('conf_id', $sys['name']);
        $this->assertSame('int', $sys['type']);
        $this->assertFalse($sys['isOptional']);

        $path = BuiltinInternalArgInfo::paramInfoForFunction('posix_pathconf', 0);
        $this->assertNotNull($path);
        $this->assertSame('path', $path['name']);
        $this->assertSame('string', $path['type']);

        $fd = BuiltinInternalArgInfo::paramInfoForFunction('posix_fpathconf', 0);
        $this->assertNotNull($fd);
        $this->assertSame('file_descriptor', $fd['name']);
        $this->assertSame('', $fd['type']);
    }

    public function testVmReflectionAndNamedArgsOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.3';
        try {
            $code = file_get_contents(__DIR__.'/../repro/issue_27918_posix_sysconf_reflection.php');
            $this->assertNotFalse($code);
            $rt = new Runtime();
            $block = $rt->parseAndCompile($code, 'issue_27918.php');
            ob_start();
            $rt->run($block);
            $this->assertSame(
                "posix_sysconf(int \$conf_id):int\n".
                "posix_sysconf_argc=1 req=1\n".
                "posix_pathconf(string \$path, int \$name):int|false\n".
                "posix_pathconf_argc=2 req=2\n".
                "posix_fpathconf(? \$file_descriptor, int \$name):int|false\n".
                "posix_fpathconf_argc=2 req=2\n".
                "sysconf_named=ok\n".
                "pathconf_named=ok\n".
                "fpathconf_named=ok\n",
                ob_get_clean()
            );
        } finally {
            unset($_ENV['PHP_COMPILER_PROFILE']);
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
                $_ENV['PHP_COMPILER_PROFILE'] = $prev;
            }
        }
    }

    public function testVmWithholdsApisOnProfile82(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.2';
        try {
            $code = file_get_contents(__DIR__.'/../repro/issue_27918_posix_sysconf_reflection.php');
            $this->assertNotFalse($code);
            $rt = new Runtime();
            $block = $rt->parseAndCompile($code, 'issue_27918_82.php');
            ob_start();
            $rt->run($block);
            $this->assertSame(
                "posix_sysconf MISSING\n".
                "posix_pathconf MISSING\n".
                "posix_fpathconf MISSING\n",
                ob_get_clean()
            );
        } finally {
            unset($_ENV['PHP_COMPILER_PROFILE']);
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
                $_ENV['PHP_COMPILER_PROFILE'] = $prev;
            }
        }
    }
}
