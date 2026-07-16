<?php
declare(strict_types=1);
namespace PHPCompiler\ext\dom;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
final class DomContainsNullJitHelper
{
    public static function containsNullArgv(Context $ctx, ObjectEntry $node): int
    {
        unset($ctx, $node);
        return 0;
    }
}
