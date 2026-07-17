<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/**
 * VM helpers for socket_addrinfo_* (php-src ext/sockets/sockets.c; #6064).
 */
final class VmSocketAddrinfo
{
    /**
     * @param array{ai_flags?: int, ai_family?: int, ai_socktype?: int, ai_protocol?: int} $hints
     *
     * @return list<ObjectEntry>|false
     */
    public static function lookup(string $host, ?string $service, array $hints, Context $ctx): array|false
    {
        if ('' === $host) {
            return false;
        }
        if (!SocketsLibcThinAbi::available()) {
            return false;
        }

        $rows = SocketsLibcThinAbi::getaddrinfo($host, $service, $hints);
        if (false === $rows) {
            return false;
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = VmAddressInfo::wrap($row, $ctx);
        }

        return $out;
    }

    /**
     * @return array{
     *   ai_flags: int,
     *   ai_family: int,
     *   ai_socktype: int,
     *   ai_protocol: int,
     *   ai_addr: array<string, int|string>
     * }
     */
    public static function explain(ObjectEntry $object): array
    {
        $snap = VmAddressInfo::snapshotFor($object);
        if (null === $snap) {
            return [
                'ai_flags' => 0,
                'ai_family' => 0,
                'ai_socktype' => 0,
                'ai_protocol' => 0,
                'ai_addr' => [],
            ];
        }
        $addr = SocketsLibcThinAbi::explainSockaddr($snap['ai_family'], $snap['ai_addr']) ?? [];

        return [
            'ai_flags' => $snap['ai_flags'],
            'ai_family' => $snap['ai_family'],
            'ai_socktype' => $snap['ai_socktype'],
            'ai_protocol' => $snap['ai_protocol'],
            'ai_addr' => $addr,
        ];
    }

    public static function connect(ObjectEntry $address, Context $ctx, Frame $frame): ObjectEntry|false
    {
        return self::socketFromAddress($address, $ctx, $frame, 'connect');
    }

    public static function bind(ObjectEntry $address, Context $ctx, Frame $frame): ObjectEntry|false
    {
        return self::socketFromAddress($address, $ctx, $frame, 'bind');
    }

    /**
     * @param 'connect'|'bind' $op
     */
    private static function socketFromAddress(
        ObjectEntry $address,
        Context $ctx,
        Frame $frame,
        string $op
    ): ObjectEntry|false {
        $snap = VmAddressInfo::snapshotFor($address);
        if (null === $snap || !SocketsLibcThinAbi::available()) {
            return false;
        }

        $fd = SocketsLibcThinAbi::socket($snap['ai_family'], $snap['ai_socktype'], $snap['ai_protocol']);
        if ($fd < 0) {
            $errno = SocketsLibcThinAbi::readErrno();
            VmSockets::recordError(null, $errno);
            VmSockets::triggerWarning(
                $frame,
                \sprintf(
                    'socket_addrinfo_%s(): Unable to %s address [%d]: %s',
                    $op,
                    $op,
                    $errno,
                    SocketsLibcThinAbi::strerror($errno)
                )
            );

            return false;
        }

        $rc = 'bind' === $op
            ? SocketsLibcThinAbi::bindAddr($fd, $snap['ai_addr'])
            : SocketsLibcThinAbi::connectAddr($fd, $snap['ai_addr']);
        if (0 !== $rc) {
            $errno = SocketsLibcThinAbi::readErrno();
            VmSockets::recordError(null, $errno);
            SocketsLibcThinAbi::close($fd);
            VmSockets::triggerWarning(
                $frame,
                \sprintf(
                    'socket_addrinfo_%s(): Unable to %s address [%d]: %s',
                    $op,
                    $op,
                    $errno,
                    SocketsLibcThinAbi::strerror($errno)
                )
            );

            return false;
        }

        $object = VmSocket::wrapOwnedFd($fd, $ctx, $snap['ai_family']);
        VmSockets::recordError($object, 0);

        return $object;
    }
}
