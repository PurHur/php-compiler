<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: getservbyname/port + getprotobyname/number Reflection return |false (#26318). */
final class NetworkSvcProtoReflection26318VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'network_svc_proto_reflection_return_26318.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/network_svc_proto_reflection_return_26318.phpt',
            'network_svc_proto_reflection_return_26318.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
