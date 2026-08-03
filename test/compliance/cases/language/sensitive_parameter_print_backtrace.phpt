--TEST--
Language: #[\SensitiveParameter] formats as Object(SensitiveParameterValue) in debug_print_backtrace (#27124)
--FILE--
<?php
function login(#[\SensitiveParameter] string $password): void {
    debug_print_backtrace();
}
ob_start();
login('hunter2');
$out = ob_get_clean();
echo str_contains($out, 'Object(SensitiveParameterValue)') ? "object_form\n" : "no_object_form\n";
echo str_contains($out, '[Sensitive Parameter]') ? "flat_label\n" : "no_flat_label\n";
$t = debug_backtrace();
// call site above has no sensitive args — use a nested frame:
(function (#[\SensitiveParameter] string $password) {
    $t = debug_backtrace();
    echo get_class($t[0]['args'][0]), "\n";
    echo ($t[0]['args'][0] instanceof SensitiveParameterValue) ? "instanceof_ok\n" : "instanceof_fail\n";
})('hunter2');
--EXPECT--
object_form
no_flat_label
SensitiveParameterValue
instanceof_ok
