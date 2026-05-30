--TEST--
Language: #[\SensitiveParameter] formats as [Sensitive Parameter] in trace string (VM, #3351)
--FILE--
<?php
function login(#[\SensitiveParameter] string $password): void {
    $t = debug_backtrace();
    $arg = $t[0]['args'][0];
    echo $t[0]['function'], '(';
    echo $arg instanceof SensitiveParameterValue ? '[Sensitive Parameter]' : $arg;
    echo ")\n";
}
login('hunter2');
--EXPECT--
login([Sensitive Parameter])
