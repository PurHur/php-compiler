<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * request_parse_body Reflection + named options (#23878, ext/standard/http.stub.php).
 */
final class RequestParseBodyReflection23878Test extends TestCase
{
    public function testArginfoMatchesPhpSrcStub(): void
    {
        $this->assertSame(['options='], BuiltinParamNames::forFunction('request_parse_body'));
        $this->assertSame(1, BuiltinParamNames::paramCountForInternalFunction('request_parse_body'));
        $this->assertSame(0, BuiltinParamNames::requiredParamCountForInternalFunction('request_parse_body'));

        $this->assertSame('array', BuiltinInternalArgInfo::returnTypeLabelForFunction('request_parse_body'));
        $this->assertSame('?array', BuiltinInternalArgInfo::stubParamTypeOverride('request_parse_body', 0));

        $opt = BuiltinInternalArgInfo::paramInfoForFunction('request_parse_body', 0);
        $this->assertNotNull($opt);
        $this->assertSame('options', $opt['name']);
        $this->assertSame('?array', $opt['type']);
        $this->assertTrue($opt['isOptional']);
    }

    public function testVmReflectionAndNamedArgsOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        try {
            $code = file_get_contents(__DIR__.'/../repro/issue_23878_request_parse_body_reflection.php');
            $this->assertNotFalse($code);
            $rt = new Runtime();
            $block = $rt->parseAndCompile($code, 'issue_23878.php');
            ob_start();
            $rt->run($block);
            $this->assertSame(
                "request_parse_body(?array \$options=):array\n".
                "argc=1 req=0\n".
                "named_bound=Request does not provide a content type\n",
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

    public function testVmWithholdsApiOnProfile82(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.2';
        try {
            $code = file_get_contents(__DIR__.'/../repro/issue_23878_request_parse_body_reflection.php');
            $this->assertNotFalse($code);
            $rt = new Runtime();
            $block = $rt->parseAndCompile($code, 'issue_23878_82.php');
            ob_start();
            $rt->run($block);
            $this->assertSame("request_parse_body MISSING\n", ob_get_clean());
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
