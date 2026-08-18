<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: ldap_start_tls() bool + errno (#32109).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 *
 * @group llvm
 */
final class LdapStartTls32109JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'ldap_start_tls.phpt' => self::parsePHPT(
            __DIR__.'/cases/ext/ldap_start_tls.phpt',
            'ldap_start_tls.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
