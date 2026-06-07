--TEST--
Language: builtin attribute classes class_exists and isInternal (#6303)
--FILE--
<?php
declare(strict_types=1);
foreach (['Attribute', 'ReturnTypeWillChange', 'AllowDynamicProperties', 'SensitiveParameter', 'Override'] as $c) {
    echo $c, '=', class_exists($c, false) ? 'yes' : 'no', "\n";
    if (class_exists($c, false)) {
        echo (new ReflectionClass($c))->isInternal() ? 'internal' : 'user', "\n";
    }
}
--EXPECT--
Attribute=yes
internal
ReturnTypeWillChange=yes
internal
AllowDynamicProperties=yes
internal
SensitiveParameter=yes
internal
Override=yes
internal
