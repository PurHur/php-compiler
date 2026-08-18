<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: ldap_set_option()/ldap_get_option() int subset (#32107).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class LdapSetGetOption32107VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'ldap_set_get_option_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/ext/ldap_set_get_option_jit.phpt',
            'ldap_set_get_option_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
