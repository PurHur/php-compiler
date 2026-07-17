<?php

declare(strict_types=1);

namespace PHPCompiler\ext\reflection;

use PHPCompiler\ext\standard\GetClassJitHelper;

/** isAnonymousClass() class-id table probe for JIT/AOT (#19969). */
final class IsAnonymousClassJitHelper
{
    public static function probeClassId(int $classId): bool
    {
        $name = GetClassJitHelper::classNameFromClassId($classId);

        return '' !== $name && str_contains($name, '@anonymous');
    }
}
