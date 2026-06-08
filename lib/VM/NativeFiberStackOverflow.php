<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/** Native bridge for FiberStackOverflow (#7267; php-src ext/fiber/fiber.stub.php). */
final class NativeFiberStackOverflow extends \Error
{
}
