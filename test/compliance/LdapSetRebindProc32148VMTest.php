<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: ldap_set_rebind_proc() null-clear + TypeError (#32148).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class LdapSetRebindProc32148VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'ldap_set_rebind_proc_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/ext/ldap_set_rebind_proc_jit.phpt',
            'ldap_set_rebind_proc_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
