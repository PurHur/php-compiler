<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * posix_getrlimit/posix_setrlimit Reflection + named args (#27736, ext/posix/posix.stub.php).
 */
final class PosixRlimitReflectionTest extends TestCase
{
    public function testArginfoMatchesPhpSrcStub(): void
    {
        $this->assertNull(BuiltinParamNames::forFunction('posix_getrlimit'));
        $this->assertSame(['resource', 'soft_limit', 'hard_limit'], BuiltinParamNames::forFunction('posix_setrlimit'));
        $this->assertSame(0, BuiltinParamNames::paramCountForInternalFunction('posix_getrlimit'));
        $this->assertSame(3, BuiltinParamNames::paramCountForInternalFunction('posix_setrlimit'));
        $this->assertSame(0, BuiltinParamNames::requiredParamCountForInternalFunction('posix_getrlimit'));
        $this->assertSame(3, BuiltinParamNames::requiredParamCountForInternalFunction('posix_setrlimit'));

        $this->assertSame('array|false', BuiltinInternalArgInfo::returnTypeLabelForFunction('posix_getrlimit'));
        $this->assertSame('bool', BuiltinInternalArgInfo::returnTypeLabelForFunction('posix_setrlimit'));

        $res = BuiltinInternalArgInfo::paramInfoForFunction('posix_setrlimit', 0);
        $this->assertNotNull($res);
        $this->assertSame('resource', $res['name']);
        $this->assertSame('int', $res['type']);
        $this->assertFalse($res['isOptional']);
    }

    public function testVmReflectionAndNamedArgs(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_27736_posix_rlimit_reflection.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_27736.php');
        ob_start();
        $rt->run($block);
        $this->assertSame(
            "posix_getrlimit arity=0 req=0 return=array|false\n".
            "posix_setrlimit arity=3 req=3 return=bool\n".
            "  resource:int\n".
            "  soft_limit:int\n".
            "  hard_limit:int\n".
            "named=ok\n",
            ob_get_clean()
        );
    }
}
