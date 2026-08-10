<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: Pdo\Sqlite::setAuthorizer PROFILE gates (#27676). */
final class PdoSqliteSetAuthorizerVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'pdo_sqlite_set_authorizer_85.phpt' => self::parsePHPT(
            __DIR__.'/cases/pdo/pdo_sqlite_set_authorizer_85.phpt',
            'pdo_sqlite_set_authorizer_85.phpt'
        );
        yield 'pdo_sqlite_set_authorizer_84.phpt' => self::parsePHPT(
            __DIR__.'/cases/pdo/pdo_sqlite_set_authorizer_84.phpt',
            'pdo_sqlite_set_authorizer_84.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
