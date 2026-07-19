<?php

declare(strict_types=1);

/**
 * Global ZMQException when PECL ext-zmq is not loaded on the host (#6443).
 */
if (!\class_exists(\ZMQException::class, false)) {
    class ZMQException extends \Exception
    {
    }
}
