<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: ldap_bind_ext() Result|false (#32146).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class LdapBindExt32146VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'ldap_bind_ext.phpt' => self::parsePHPT(
            __DIR__.'/cases/ext/ldap_bind_ext.phpt',
            'ldap_bind_ext.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
