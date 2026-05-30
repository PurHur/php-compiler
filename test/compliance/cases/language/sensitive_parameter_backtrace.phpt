--TEST--
Language: #[\SensitiveParameter] redacts debug_backtrace args (VM, #3351)
--FILE--
<?php
function login(#[\SensitiveParameter] string $password): void {
    $t = debug_backtrace();
    echo get_class($t[0]['args'][0]), "\n";
}
login('hunter2');
--EXPECT--
SensitiveParameterValue
