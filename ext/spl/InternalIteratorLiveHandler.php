<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

/**
 * BC alias — interface lives in lib/VM (#36204).
 *
 * Keep this file so spine / inventory paths and existing {@code implements}
 * under ext/ continue to resolve until call sites migrate.
 */
interface InternalIteratorLiveHandler extends \PHPCompiler\VM\InternalIteratorLiveHandler
{
}
