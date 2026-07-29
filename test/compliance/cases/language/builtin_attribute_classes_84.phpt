--TEST--
Language: PHP 8.5 builtin attribute classes class_exists and isInternal (#7101, #24946)
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
declare(strict_types=1);
foreach (['DelayedTargetValidation', 'CompileTime', 'NoDiscard'] as $c) {
    echo $c, '=', class_exists($c, false) ? 'yes' : 'no', "\n";
    if (class_exists($c, false)) {
        echo (new ReflectionClass($c))->isInternal() ? 'internal' : 'user', "\n";
    }
}

#[DelayedTargetValidation]
class DtvProbe {}

echo 'dtv=', class_exists('DtvProbe', false) ? 'yes' : 'no', "\n";
--EXPECT--
DelayedTargetValidation=yes
internal
CompileTime=yes
internal
NoDiscard=yes
internal
dtv=yes
