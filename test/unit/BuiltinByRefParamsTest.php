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

    public function testCollatorAsortArrayArgument(): void
    {
        $this->assertSame([1], BuiltinByRefParams::forFunction('Collator::asort'));
        $this->assertSame([1], BuiltinByRefParams::forFunction('collator::asort'));
        $this->assertSame([0], BuiltinByRefParams::forFunction('asort'));
    }

    public function testRedisScanIteratorByRef(): void
    {
        $this->assertSame([1], BuiltinByRefParams::forFunction('Redis::scan'));
        $this->assertSame([2], BuiltinByRefParams::forFunction('Redis::hScan'));
        $this->assertSame([2], BuiltinByRefParams::forFunction('Redis::sScan'));
        $this->assertSame([2], BuiltinByRefParams::forFunction('Redis::zScan'));
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

    public function testOpensslPublicEncryptPrivateDecryptOutputArguments(): void
    {
        foreach (['openssl_public_encrypt', 'openssl_private_decrypt', 'openssl_private_encrypt', 'openssl_public_decrypt'] as $fn) {
            $this->assertSame([1], BuiltinByRefParams::forFunction($fn), $fn);
        }
        $this->assertSame([1], BuiltinByRefParams::forFunction('OPENSSL_PUBLIC_ENCRYPT'));
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
        $this->assertSame([1], BuiltinByRefParams::forFunction('openssl_cms_read'));
        $this->assertSame([1], BuiltinByRefParams::forFunction('openssl_pkcs7_read'));
    }

    public function testOpensslX509ExportByRefIndices(): void
    {
        $this->assertSame([1], BuiltinByRefParams::forFunction('openssl_x509_export'));
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

    public function testCurlMultiExecStillRunningByRef(): void
    {
        $this->assertSame([1], BuiltinByRefParams::forFunction('curl_multi_exec'));
        $this->assertSame([1], BuiltinByRefParams::forFunction('CURL_MULTI_EXEC'));
        $this->assertTrue(BuiltinByRefParams::isByRefArg('curl_multi_exec', 1));
        $this->assertFalse(BuiltinByRefParams::isByRefArg('curl_multi_exec', 0));
    }

    /** Issue #19637 — preg_replace_callback() &$count must be ZEND_SEND_REF like preg_replace(). */
    public function testPregReplaceFamilyCountByRef(): void
    {
        $this->assertSame([4], BuiltinByRefParams::forFunction('preg_replace'));
        $this->assertSame([4], BuiltinByRefParams::forFunction('preg_filter'));
        $this->assertSame([4], BuiltinByRefParams::forFunction('preg_replace_callback'));
        $this->assertSame([4], BuiltinByRefParams::forFunction('PREG_REPLACE_CALLBACK'));
        $this->assertSame([3], BuiltinByRefParams::forFunction('preg_replace_callback_array'));
        $this->assertTrue(BuiltinByRefParams::isByRefArg('preg_replace_callback', 4));
        $this->assertFalse(BuiltinByRefParams::isByRefArg('preg_replace_callback', 3));
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

    public function testSocketCreatePairPairByRef(): void
    {
        $this->assertSame([3], BuiltinByRefParams::forFunction('socket_create_pair'));
        $this->assertSame([3], BuiltinByRefParams::forFunction('SOCKET_CREATE_PAIR'));
    }

    public function testSocketSelectArraysByRef(): void
    {
        $this->assertSame([0, 1, 2], BuiltinByRefParams::forFunction('socket_select'));
        $this->assertSame([0, 1, 2], BuiltinByRefParams::forFunction('SOCKET_SELECT'));
    }

    public function testSocketDatagramByRef(): void
    {
        $this->assertSame([1, 2], BuiltinByRefParams::forFunction('socket_getsockname'));
        $this->assertSame([1, 2], BuiltinByRefParams::forFunction('socket_getpeername'));
        $this->assertSame([1, 4, 5], BuiltinByRefParams::forFunction('socket_recvfrom'));
        $this->assertSame([1], BuiltinByRefParams::forFunction('socket_recv'));
        $this->assertSame([1], BuiltinByRefParams::forFunction('socket_recvmsg'));
    }

    public function testStreamSocketServerErrnoErrstrByRef(): void
    {
        $this->assertSame([1, 2], BuiltinByRefParams::forFunction('stream_socket_server'));
        $this->assertSame([1, 2], BuiltinByRefParams::forFunction('STREAM_SOCKET_SERVER'));
    }

    public function testStreamContextMutatorsFirstArgByRef(): void
    {
        foreach (['stream_context_set_options', 'stream_context_set_params'] as $fn) {
            $this->assertSame([0], BuiltinByRefParams::forFunction($fn), $fn);
        }
        // php-src basic_functions.stub.php — $context not by-ref (#25845)
        $this->assertSame([], BuiltinByRefParams::forFunction('stream_context_set_option'));
    }

    public function testSodiumMemzeroFirstArgByRef(): void
    {
        $this->assertSame([0], BuiltinByRefParams::forFunction('sodium_memzero'));
        $this->assertSame([0], BuiltinByRefParams::forFunction('SODIUM_MEMZERO'));
        $this->assertSame([0], BuiltinByRefParams::forFunction('sodium_increment'));
        $this->assertSame([0], BuiltinByRefParams::forFunction('sodium_add'));
    }

    public function testSodiumGenerichashStreamingStateByRef(): void
    {
        $this->assertSame([0], BuiltinByRefParams::forFunction('sodium_crypto_generichash_update'));
        $this->assertSame([0], BuiltinByRefParams::forFunction('sodium_crypto_generichash_final'));
        $this->assertSame([0], BuiltinByRefParams::forFunction('SODIUM_CRYPTO_GENERICHASH_UPDATE'));
    }

    public function testPcntlWaitpidStatusByRef(): void
    {
        $this->assertSame([0], BuiltinByRefParams::forFunction('pcntl_wait'));
        $this->assertSame([0], BuiltinByRefParams::forFunction('PCNTL_WAIT'));
        // &$status + &$resource_usage — php-src ext/pcntl/pcntl.stub.php (#19564, #27849)
        $this->assertSame([1, 3], BuiltinByRefParams::forFunction('pcntl_waitpid'));
        $this->assertSame([1, 3], BuiltinByRefParams::forFunction('PCNTL_WAITPID'));
        $this->assertSame([2], BuiltinByRefParams::forFunction('pcntl_sigprocmask'));
        $this->assertSame([1], BuiltinByRefParams::forFunction('pcntl_sigtimedwait'));
        $this->assertSame([2], BuiltinByRefParams::forFunction('pcntl_waitid'));
    }

    public function testPdoStatementBindParam(): void
    {
        $this->assertSame([2], BuiltinByRefParams::forFunction('PDOStatement::bindParam'));
        $this->assertSame([2], BuiltinByRefParams::forFunction('pdostatement::bindparam'));
        $this->assertSame([2], BuiltinByRefParams::forFunction('SQLite3Stmt::bindParam'));
    }

    public function testNumfmtParseCurrencyOffsetByRef(): void
    {
        // &$currency + optional &$offset — php-src formatter.stub.php (#21127)
        $this->assertSame([2, 3], BuiltinByRefParams::forFunction('numfmt_parse_currency'));
        $this->assertSame([2, 3], BuiltinByRefParams::forFunction('NumberFormatter::parseCurrency'));
        $this->assertSame([2, 3], BuiltinByRefParams::forFunction('numberformatter::parsecurrency'));
    }

    public function testNumfmtParseOffsetByRef(): void
    {
        // optional &$offset after $type — php-src formatter.stub.php (#21139)
        $this->assertSame([3], BuiltinByRefParams::forFunction('numfmt_parse'));
        $this->assertSame([3], BuiltinByRefParams::forFunction('NumberFormatter::parse'));
        $this->assertSame([3], BuiltinByRefParams::forFunction('numberformatter::parse'));
    }

    public function testIntlDateFormatterParseOffsetByRef(): void
    {
        // &$offset — php-src dateformat.stub.php (#20729, #22622)
        $this->assertSame([2], BuiltinByRefParams::forFunction('IntlDateFormatter::parse'));
        $this->assertSame([2], BuiltinByRefParams::forFunction('IntlDateFormatter::parseToCalendar'));
        $this->assertSame([2], BuiltinByRefParams::forFunction('intldateformatter::parsetocalendar'));
        $this->assertSame([2], BuiltinByRefParams::forFunction('IntlDateFormatter::localtime'));
        $this->assertSame([2], BuiltinByRefParams::forFunction('datefmt_parse'));
        $this->assertSame([2], BuiltinByRefParams::forFunction('datefmt_localtime'));
    }
}
