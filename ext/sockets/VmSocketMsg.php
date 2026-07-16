<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;

/**
 * VM helpers for socket_sendmsg / socket_recvmsg / socket_cmsg_space (#6333).
 *
 * php-src: ext/sockets/sendrecvmsg.c
 */
final class VmSocketMsg
{
    /** @var array<string, array{size: int, var_el_size: int}> */
    private const ANCILLARY = [
        '1:1' => ['size' => 0, 'var_el_size' => 4],   // SOL_SOCKET + SCM_RIGHTS
        '1:2' => ['size' => 12, 'var_el_size' => 0],  // SOL_SOCKET + SCM_CREDENTIALS
    ];

    public static function cmsgSpace(int $level, int $type, int $num): ?int
    {
        $key = $level.':'.$type;
        if (!isset(self::ANCILLARY[$key])) {
            throw new \ValueError(\sprintf(
                'Pair level %d and/or type %d is not supported',
                $level,
                $type
            ));
        }
        if ($num < 0) {
            throw new \ValueError(
                'socket_cmsg_space(): Argument #3 ($num) must be greater than or equal to 0'
            );
        }
        $entry = self::ANCILLARY[$key];
        $dataLen = $entry['size'] + $num * $entry['var_el_size'];

        return SocketsLibcThinAbi::cmsgSpace($dataLen);
    }

    /**
     * @param array{iov?: list<string>, control?: list<array{level: int, type: int, data: mixed}>, name?: array<string, mixed>} $message
     */
    public static function sendmsg(ObjectEntry $object, array $message, int $flags, Frame $frame): int|false
    {
        $fd = VmSocket::fdForObject($object);
        if (null === $fd) {
            return false;
        }
        $iov = $message['iov'] ?? [];
        if ([] === $iov) {
            VmSockets::triggerWarning(
                $frame,
                'socket_sendmsg(): error converting user data (path: msghdr): The key \'iov\' is required'
            );

            return false;
        }
        $control = '';
        if (isset($message['control']) && \is_array($message['control']) && [] !== $message['control']) {
            $control = self::buildControl($message['control'], $frame);
            if (null === $control) {
                return false;
            }
        }
        $name = null;
        if (isset($message['name']) && \is_array($message['name'])) {
            $name = self::marshalName($message['name'], $frame);
            if (null === $name) {
                return false;
            }
        }
        $n = SocketsLibcThinAbi::sendmsg($fd, $iov, $control, $flags, $name);
        if ($n < 0) {
            $errno = SocketsLibcThinAbi::readErrno();
            VmSockets::recordError($object, $errno);
            VmSockets::triggerWarning(
                $frame,
                \sprintf(
                    'socket_sendmsg(): error in sendmsg [%d]: %s',
                    $errno,
                    SocketsLibcThinAbi::strerror($errno)
                )
            );

            return false;
        }
        VmSockets::recordError($object, 0);

        return $n;
    }

    /**
     * @param array{buffer_size?: int, controllen?: int} $message
     *
     * @return array{bytes: int, message: array{name: array<string, int|string>|null, control: array, iov: list<string>, flags: int}}|false
     */
    public static function recvmsg(ObjectEntry $object, array $message, int $flags, Frame $frame): array|false
    {
        $fd = VmSocket::fdForObject($object);
        if (null === $fd) {
            return false;
        }
        if (!isset($message['controllen'])) {
            VmSockets::triggerWarning(
                $frame,
                'socket_recvmsg(): error converting user data (path: msghdr): The key \'controllen\' is required'
            );

            return false;
        }
        $controllen = (int) $message['controllen'];
        if ($controllen <= 0) {
            VmSockets::triggerWarning(
                $frame,
                'socket_recvmsg(): error converting user data (path: msghdr > controllen): controllen cannot be 0'
            );

            return false;
        }
        $bufferSize = isset($message['buffer_size']) ? (int) $message['buffer_size'] : 8192;
        if ($bufferSize < 1) {
            $bufferSize = 1;
        }
        $got = SocketsLibcThinAbi::recvmsg($fd, $bufferSize, $controllen, $flags);
        if (false === $got) {
            $errno = SocketsLibcThinAbi::readErrno();
            VmSockets::recordError($object, $errno);
            VmSockets::triggerWarning(
                $frame,
                \sprintf(
                    'socket_recvmsg(): error in recvmsg [%d]: %s',
                    $errno,
                    SocketsLibcThinAbi::strerror($errno)
                )
            );

            return false;
        }
        VmSockets::recordError($object, 0);
        $name = self::unmarshalName($got[4] ?? '');
        $control = self::parseControlBuffer($got[2] ?? '', $frame);

        return [
            'bytes' => $got[0],
            'message' => [
                'name' => $name,
                'control' => $control,
                'iov' => [$got[1]],
                'flags' => $got[3],
            ],
        ];
    }

    /**
     * Parse ancillary control buffer from recvmsg into PHP control array (#19407).
     *
     * @return list<array{level: int, type: int, data: list<ObjectEntry>}>
     */
    private static function parseControlBuffer(string $control, Frame $frame): array
    {
        if ('' === $control) {
            return [];
        }
        $out = [];
        $len = \strlen($control);
        $offset = 0;
        while ($offset + 16 <= $len) {
            $hdr = \unpack('Pcmsg_len/llevel/ltype', \substr($control, $offset, 16));
            if (!\is_array($hdr)) {
                break;
            }
            $cmsgLen = (int) ($hdr['cmsg_len'] ?? 0);
            if ($cmsgLen < 16) {
                break;
            }
            $level = (int) ($hdr['level'] ?? 0);
            $type = (int) ($hdr['type'] ?? 0);
            $dataOffset = $offset + 16;
            $dataLen = $cmsgLen - 16;
            if ($dataOffset + $dataLen > $len) {
                break;
            }
            if (1 === $level && 1 === $type && $dataLen >= 4) {
                $socks = [];
                for ($i = 0; $i + 4 <= $dataLen; $i += 4) {
                    $fd = \unpack('l', \substr($control, $dataOffset + $i, 4));
                    if (!\is_array($fd)) {
                        continue;
                    }
                    $fdVal = (int) ($fd[1] ?? -1);
                    if ($fdVal < 0) {
                        continue;
                    }
                    $socks[] = VmSocket::wrapOwnedFd($fdVal, $frame->vmContext);
                }
                $out[] = ['level' => $level, 'type' => $type, 'data' => $socks];
            }
            $offset += SocketsLibcThinAbi::cmsgAlign($cmsgLen);
        }

        return $out;
    }

    /**
     * Marshal PHP name array to sockaddr bytes (php-src conversions.c; #19408).
     *
     * @param array<string, mixed> $name
     */
    private static function marshalName(array $name, Frame $frame): ?string
    {
        $family = VmSockets::AF_INET;
        if (isset($name['family'])) {
            $family = (int) $name['family'];
        }
        if (VmSockets::AF_INET === $family) {
            if (!isset($name['addr'], $name['port'])) {
                VmSockets::triggerWarning(
                    $frame,
                    'socket_sendmsg(): error converting user data (path: msghdr > name): AF_INET name requires addr and port'
                );

                return null;
            }
            $addr = \is_string($name['addr']) ? $name['addr'] : (string) $name['addr'];
            $port = (int) $name['port'];
            $packed = SocketsLibcThinAbi::packSockaddrIn($addr, $port);
            if (null === $packed) {
                VmSockets::triggerWarning(
                    $frame,
                    \sprintf(
                        'socket_sendmsg(): could not resolve address \'%s\' to get an AF_INET address',
                        $addr
                    )
                );

                return null;
            }

            return $packed;
        }
        VmSockets::triggerWarning(
            $frame,
            'socket_sendmsg(): unsupported sockaddr family for name (#19408)'
        );

        return null;
    }

    /**
     * Unmarshal sockaddr bytes to PHP name array (php-src conversions.c; #19408).
     *
     * @return array<string, int|string>|null
     */
    private static function unmarshalName(string $sockaddr): ?array
    {
        if ('' === $sockaddr || \strlen($sockaddr) < 2) {
            return null;
        }
        $family = \ord($sockaddr[0]) | (\ord($sockaddr[1]) << 8);
        if (0 === $family) {
            return null;
        }
        $explained = SocketsLibcThinAbi::explainSockaddr($family, $sockaddr);
        if (null === $explained) {
            return null;
        }
        if (isset($explained['sin_addr'], $explained['sin_port'])) {
            return [
                'family' => VmSockets::AF_INET,
                'addr' => (string) $explained['sin_addr'],
                'port' => (int) $explained['sin_port'],
            ];
        }
        if (isset($explained['sin6_addr'], $explained['sin6_port'])) {
            return [
                'family' => VmSockets::AF_INET6,
                'addr' => (string) $explained['sin6_addr'],
                'port' => (int) $explained['sin6_port'],
            ];
        }

        return null;
    }

    /**
     * @param list<array{level: int, type: int, data: mixed}> $control
     */
    private static function buildControl(array $control, Frame $frame): ?string
    {
        // v1: only empty or single SCM_RIGHTS with Socket list — otherwise warn+false
        if (1 !== \count($control)) {
            VmSockets::triggerWarning(
                $frame,
                'socket_sendmsg(): multiple control messages not implemented yet (#6333)'
            );

            return null;
        }
        $c = $control[0];
        $level = (int) ($c['level'] ?? 0);
        $type = (int) ($c['type'] ?? 0);
        if (1 !== $level || 1 !== $type) {
            VmSockets::triggerWarning(
                $frame,
                'socket_sendmsg(): unsupported control message level/type (#6333)'
            );

            return null;
        }
        $data = $c['data'] ?? null;
        if (!\is_array($data)) {
            VmSockets::triggerWarning(
                $frame,
                'socket_sendmsg(): SCM_RIGHTS data must be an array of Socket'
            );

            return null;
        }
        $fds = [];
        foreach ($data as $item) {
            if (!$item instanceof ObjectEntry || !VmSocket::isSocketObject($item)) {
                VmSockets::triggerWarning(
                    $frame,
                    'socket_sendmsg(): SCM_RIGHTS data must be an array of Socket'
                );

                return null;
            }
            $fd = VmSocket::fdForObject($item);
            if (null === $fd) {
                return null;
            }
            $fds[] = $fd;
        }
        $dataLen = \count($fds) * 4;
        $space = SocketsLibcThinAbi::cmsgSpace($dataLen);
        $cmsgLen = 16 + $dataLen;
        $packed = \pack('Qii', $cmsgLen, 1, 1);
        $packed = \str_pad($packed, 16, "\0");
        foreach ($fds as $fd) {
            $packed .= \pack('i', $fd);
        }

        return \str_pad($packed, $space, "\0");
    }

    /**
     * Parse PHP message HashTable into native-ish array for sendmsg.
     *
     * @return array{iov: list<string>, control?: list<array{level: int, type: int, data: mixed}>, name?: array<string, mixed>}|null
     */
    public static function parseSendMessage(HashTable $ht, Frame $frame): ?array
    {
        $iov = null;
        $control = null;
        $name = null;
        foreach ($ht->iterateKeyed(true) as [$keyVar, $val]) {
            $key = $keyVar->resolveIndirect()->toString();
            if ('iov' === $key) {
                if (Variable::TYPE_ARRAY !== $val->type) {
                    VmSockets::triggerWarning(
                        $frame,
                        'socket_sendmsg(): error converting user data (path: msghdr > iov): expected array'
                    );

                    return null;
                }
                $iov = [];
                foreach ($val->toArray()->iterateKeyed(true) as [$_, $chunk]) {
                    $iov[] = VmString::coerceOperand($chunk->resolveIndirect());
                }
            } elseif ('control' === $key && Variable::TYPE_ARRAY === $val->type) {
                $control = self::parseControlArray($val->toArray(), $frame);
                if (null === $control) {
                    return null;
                }
            } elseif ('name' === $key && Variable::TYPE_ARRAY === $val->type) {
                $name = self::parseNameArray($val->toArray(), $frame);
                if (null === $name) {
                    return null;
                }
            }
        }
        if (null === $iov) {
            VmSockets::triggerWarning(
                $frame,
                'socket_sendmsg(): error converting user data (path: msghdr): The key \'iov\' is required'
            );

            return null;
        }

        $out = ['iov' => $iov];
        if (null !== $control) {
            $out['control'] = $control;
        }
        if (null !== $name) {
            $out['name'] = $name;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function parseNameArray(HashTable $ht, Frame $frame): ?array
    {
        $out = [];
        foreach ($ht->iterateKeyed(true) as [$keyVar, $val]) {
            $key = $keyVar->resolveIndirect()->toString();
            if ('family' === $key || 'port' === $key) {
                $out[$key] = VmMath::parseIntBuiltinArg($val, 'socket_sendmsg', 1, 'message');
            } elseif ('addr' === $key) {
                $out[$key] = VmString::coerceOperand($val->resolveIndirect());
            } elseif ('path' === $key) {
                $out[$key] = VmString::coerceOperand($val->resolveIndirect());
            }
        }

        return $out;
    }

    /**
     * @return array{buffer_size?: int, controllen: int}|null
     */
    public static function parseRecvMessage(HashTable $ht, Frame $frame): ?array
    {
        $out = [];
        $hasControllen = false;
        foreach ($ht->iterateKeyed(true) as [$keyVar, $val]) {
            $key = $keyVar->resolveIndirect()->toString();
            if ('controllen' === $key) {
                $hasControllen = true;
                $out['controllen'] = VmMath::parseIntBuiltinArg($val, 'socket_recvmsg', 1, 'message');
            } elseif ('buffer_size' === $key) {
                $out['buffer_size'] = VmMath::parseIntBuiltinArg($val, 'socket_recvmsg', 1, 'message');
            }
        }
        if (!$hasControllen) {
            VmSockets::triggerWarning(
                $frame,
                'socket_recvmsg(): error converting user data (path: msghdr): The key \'controllen\' is required'
            );

            return null;
        }

        return $out;
    }

    /**
     * @return list<array{level: int, type: int, data: mixed}>|null
     */
    private static function parseControlArray(HashTable $ht, Frame $frame): ?array
    {
        $out = [];
        foreach ($ht->iterateKeyed(true) as [$_, $entryVar]) {
            if (Variable::TYPE_ARRAY !== $entryVar->type) {
                continue;
            }
            $level = null;
            $type = null;
            $data = null;
            foreach ($entryVar->toArray()->iterateKeyed(true) as [$kVar, $v]) {
                $k = $kVar->resolveIndirect()->toString();
                if ('level' === $k) {
                    $level = VmMath::parseIntBuiltinArg($v, 'socket_sendmsg', 1, 'message');
                } elseif ('type' === $k) {
                    $type = VmMath::parseIntBuiltinArg($v, 'socket_sendmsg', 1, 'message');
                } elseif ('data' === $k) {
                    if (Variable::TYPE_ARRAY === $v->type) {
                        $socks = [];
                        foreach ($v->toArray()->iterateKeyed(true) as [$_2, $sv]) {
                            if (Variable::TYPE_OBJECT === $sv->type) {
                                $socks[] = $sv->toObject();
                            }
                        }
                        $data = $socks;
                    } else {
                        $data = VmMath::parseIntBuiltinArg($v, 'socket_sendmsg', 1, 'message');
                    }
                }
            }
            if (null === $level || null === $type) {
                VmSockets::triggerWarning(
                    $frame,
                    'socket_sendmsg(): control message requires level and type'
                );

                return null;
            }
            $out[] = ['level' => $level, 'type' => $type, 'data' => $data];
        }

        return $out;
    }
}
