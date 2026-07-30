<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zmq;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * zmq extension module entry (php/pecl-networking-zmq; #6443 / #23964).
 *
 * Phase-0: procedural zmq_context/socket/bind/connect/send/recv/poll + ZMQ constants.
 * Inproc:// PAIR is pure PHP (no libzmq / runtime/*.c). Advertise only when
 * {@see ZmqExtensionPolicy::advertisesExtension()}.
 */
class Module extends ModuleAbstract
{
    /** PECL php-zmq version style */
    private const ZMQ_VERSION = '1.1.3';

    public function getExtensionName(): string
    {
        return 'zmq';
    }

    public function getExtensionVersion(): string
    {
        return self::ZMQ_VERSION;
    }

    public function init(Runtime $runtime): void
    {
        require_once __DIR__.'/bootstrap_zmqexception.php';
        parent::init($runtime);
        if (!ZmqExtensionPolicy::advertisesClasses()) {
            return;
        }
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getFunctions(): array
    {
        if (!ZmqExtensionPolicy::advertisesBuiltins()) {
            return [];
        }

        return [
            new zmq_context(),
            new zmq_socket(),
            new zmq_bind(),
            new zmq_connect(),
            new zmq_send(),
            new zmq_recv(),
            new zmq_poll(),
            new zmq_msg_read(),
        ];
    }
}
