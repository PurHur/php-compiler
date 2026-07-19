<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zmq;

/**
 * ZMQ class constants (php/pecl-networking-zmq zmq_class.c / zmq.h; #6443).
 *
 * Storage keys are lowercase (ClassName::CONST lookup). Display names stay Zend casing.
 */
final class ZmqConstants
{
    public const SOCKET_PAIR = 0;
    public const SOCKET_PUB = 1;
    public const SOCKET_SUB = 2;
    public const SOCKET_REQ = 3;
    public const SOCKET_REP = 4;
    public const SOCKET_DEALER = 5;
    public const SOCKET_ROUTER = 6;
    public const SOCKET_PULL = 7;
    public const SOCKET_PUSH = 8;
    public const SOCKET_XPUB = 9;
    public const SOCKET_XSUB = 10;
    public const SOCKET_STREAM = 11;

    public const POLL_IN = 1;
    public const POLL_OUT = 2;

    /** @var array<string, int> lowercase storage key => value */
    public const CLASS_CONSTANTS = [
        'socket_pair' => self::SOCKET_PAIR,
        'socket_pub' => self::SOCKET_PUB,
        'socket_sub' => self::SOCKET_SUB,
        'socket_req' => self::SOCKET_REQ,
        'socket_rep' => self::SOCKET_REP,
        'socket_dealer' => self::SOCKET_DEALER,
        'socket_router' => self::SOCKET_ROUTER,
        'socket_pull' => self::SOCKET_PULL,
        'socket_push' => self::SOCKET_PUSH,
        'socket_xpub' => self::SOCKET_XPUB,
        'socket_xsub' => self::SOCKET_XSUB,
        'socket_stream' => self::SOCKET_STREAM,
        'poll_in' => self::POLL_IN,
        'poll_out' => self::POLL_OUT,
    ];

    /** @var array<string, string> lowercase storage key => display name */
    public const CLASS_CONSTANT_NAMES = [
        'socket_pair' => 'SOCKET_PAIR',
        'socket_pub' => 'SOCKET_PUB',
        'socket_sub' => 'SOCKET_SUB',
        'socket_req' => 'SOCKET_REQ',
        'socket_rep' => 'SOCKET_REP',
        'socket_dealer' => 'SOCKET_DEALER',
        'socket_router' => 'SOCKET_ROUTER',
        'socket_pull' => 'SOCKET_PULL',
        'socket_push' => 'SOCKET_PUSH',
        'socket_xpub' => 'SOCKET_XPUB',
        'socket_xsub' => 'SOCKET_XSUB',
        'socket_stream' => 'SOCKET_STREAM',
        'poll_in' => 'POLL_IN',
        'poll_out' => 'POLL_OUT',
    ];
}
