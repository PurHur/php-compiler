<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\EnumSupport;
use PHPCompiler\VM\Variable;

/**
 * Register ext/sockets builtin enums (php-src ext/sockets/sockets.stub.php; issue #7235).
 */
final class BuiltinEnums
{
    public static function register(Context $ctx): void
    {
        if (!CompilerVersion::supportsBuiltinStubEnums()) {
            return;
        }

        $before = array_keys($ctx->classes);
        self::registerSocketType($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    /**
     * PHP 8.4 SocketType: int-backed enum for socket_create() $type (#7235).
     *
     * php-src: ext/sockets/sockets.stub.php — enum SocketType: int
     */
    private static function registerSocketType(Context $ctx): void
    {
        if (isset($ctx->classes['sockettype'])) {
            return;
        }

        $entry = new ClassEntry('SocketType');
        $entry->isEnum = true;
        $entry->backedType = 'int';

        self::registerBackedEnumCase($entry, 'Stream', SocketConstants::SOCK_STREAM);
        self::registerBackedEnumCase($entry, 'Datagram', SocketConstants::SOCK_DGRAM);
        self::registerBackedEnumCase($entry, 'Raw', SocketConstants::SOCK_RAW);
        self::registerBackedEnumCase($entry, 'Rdm', SocketConstants::SOCK_RDM);
        self::registerBackedEnumCase($entry, 'SeqPacket', SocketConstants::SOCK_SEQPACKET);

        EnumSupport::ensureBuiltinCasesMethod($entry);
        EnumSupport::ensureBuiltinEnumInterfaces($entry);

        $lc = 'sockettype';
        $ctx->classes[$lc] = $entry;
        $ctx->enums[$lc] = true;
    }

    private static function registerBackedEnumCase(ClassEntry $enum, string $name, int $value): void
    {
        $lc = strtolower($name);
        $backing = new Variable();
        $backing->int($value);
        $case = EnumCaseSupport::createCase($enum, $name, $backing);
        $enum->constants[$lc] = $case;
        $enum->enumCaseCanonicalNames[$lc] = $name;
        $enum->enumCases[] = [
            'name' => $name,
            'value' => $backing,
        ];
    }
}
