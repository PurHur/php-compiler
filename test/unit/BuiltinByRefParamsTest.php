<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** By-reference builtin parameter metadata (issue #3161, #3583). */
final class BuiltinByRefParamsTest extends TestCase
{
    public function testSimilarTextThirdArgument(): void
    {
        $this->assertSame([2], BuiltinByRefParams::forFunction('similar_text'));
        $this->assertSame([2], BuiltinByRefParams::forFunction('SIMILAR_TEXT'));
    }

    public function testSortFirstArgument(): void
    {
        $this->assertSame([0], BuiltinByRefParams::forFunction('sort'));
        $this->assertSame([0], BuiltinByRefParams::forFunction('SORT'));
    }

    public function testArrayWalkFirstArgument(): void
    {
        $this->assertSame([0], BuiltinByRefParams::forFunction('array_walk'));
    }

    public function testArraySpliceFirstArgument(): void
    {
        $this->assertSame([0], BuiltinByRefParams::forFunction('array_splice'));
        $this->assertSame([0], BuiltinByRefParams::forFunction('ARRAY_SPLICE'));
    }

    public function testArrayMultisortVariadicByRef(): void
    {
        $this->assertSame(0, BuiltinByRefParams::variadicByRefFromIndex('array_multisort'));
    }

    public function testArrayMultisortOnlyArraysByRef(): void
    {
        $array = new \PHPCompiler\VM\Variable();
        $array->newArray();
        $flag = new \PHPCompiler\VM\Variable();
        $flag->int(SORT_ASC);

        $this->assertTrue(BuiltinByRefParams::isByRefArg('array_multisort', 0, $array));
        $this->assertFalse(BuiltinByRefParams::isByRefArg('array_multisort', 1, $flag));
        $null = new \PHPCompiler\VM\Variable();
        $null->null();
        $this->assertFalse(BuiltinByRefParams::isByRefArg('array_multisort', 0, $null));
    }

    public function testOpensslRandomPseudoBytesSecondArgument(): void
    {
        $this->assertSame([1], BuiltinByRefParams::forFunction('openssl_random_pseudo_bytes'));
    }

    public function testOpensslSignSignatureArgument(): void
    {
        $this->assertSame([1], BuiltinByRefParams::forFunction('openssl_sign'));
        $this->assertSame([1], BuiltinByRefParams::forFunction('OPENSSL_SIGN'));
    }

    public function testOpensslSealOpenByRefIndices(): void
    {
        $this->assertSame([1, 2, 5], BuiltinByRefParams::forFunction('openssl_seal'));
        $this->assertSame([1], BuiltinByRefParams::forFunction('openssl_open'));
    }

    public function testOpensslPkcs12ByRefIndices(): void
    {
        $this->assertSame([1], BuiltinByRefParams::forFunction('openssl_pkcs12_read'));
        $this->assertSame([1], BuiltinByRefParams::forFunction('openssl_pkcs12_export'));
    }

    public function testIsCallableThirdArgument(): void
    {
        $this->assertSame([2], BuiltinByRefParams::forFunction('is_callable'));
        $this->assertSame([2], BuiltinByRefParams::forFunction('IS_CALLABLE'));
    }

    public function testPregMatchMatchesArgument(): void
    {
        $this->assertSame([2], BuiltinByRefParams::forFunction('preg_match'));
        $this->assertSame([2], BuiltinByRefParams::forFunction('PREG_MATCH_ALL'));
    }

    public function testArrayPointerFirstArgument(): void
    {
        foreach (['next', 'prev', 'reset', 'end'] as $fn) {
            $this->assertSame([0], BuiltinByRefParams::forFunction($fn), $fn);
        }
        foreach (['current', 'key'] as $fn) {
            $this->assertSame([], BuiltinByRefParams::forFunction($fn), $fn);
        }
    }

    public function testExecFamilyByRefIndices(): void
    {
        $this->assertSame([1, 2], BuiltinByRefParams::forFunction('exec'));
        $this->assertSame([1], BuiltinByRefParams::forFunction('passthru'));
        $this->assertSame([1], BuiltinByRefParams::forFunction('system'));
    }

    public function testDnsMxByRefIndices(): void
    {
        $this->assertSame([1, 2], BuiltinByRefParams::forFunction('getmxrr'));
        $this->assertSame([1, 2], BuiltinByRefParams::forFunction('dns_get_mx'));
        $this->assertSame([1, 2], BuiltinByRefParams::forFunction('GETMXRR'));
        $this->assertSame(
            ['hostname', 'mxhosts', 'weight'],
            BuiltinParamNames::forFunction('getmxrr')
        );
        $this->assertSame(
            ['hostname', 'mxhosts', 'weight'],
            BuiltinParamNames::forFunction('dns_get_mx')
        );
    }

    public function testProcOpenPipesByRef(): void
    {
        $this->assertSame([2], BuiltinByRefParams::forFunction('proc_open'));
        $this->assertSame([2], BuiltinByRefParams::forFunction('PROC_OPEN'));
    }

    public function testStreamSocketAcceptPeerByRef(): void
    {
        $this->assertSame([2], BuiltinByRefParams::forFunction('stream_socket_accept'));
        $this->assertSame([2], BuiltinByRefParams::forFunction('STREAM_SOCKET_ACCEPT'));
    }

    public function testStreamSocketServerErrnoErrstrByRef(): void
    {
        $this->assertSame([1, 2], BuiltinByRefParams::forFunction('stream_socket_server'));
        $this->assertSame([1, 2], BuiltinByRefParams::forFunction('STREAM_SOCKET_SERVER'));
    }
}
