<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: ldap_sasl_bind() bool + errno (#32147).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class LdapSaslBind32147VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'ldap_sasl_bind_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/ext/ldap_sasl_bind_jit.phpt',
            'ldap_sasl_bind_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
