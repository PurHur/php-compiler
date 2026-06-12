--TEST--
stdlib: cli_get_process_title() / cli_set_process_title() (ext/standard/cli_ops.c, #5155)
--FILE--
<?php
foreach (['cli_set_process_title', 'cli_get_process_title'] as $fn) {
    echo $fn, '=', function_exists($fn) ? 'yes' : 'no', "\n";
}
echo cli_get_process_title(), "\n";
var_export(cli_set_process_title('my-worker'));
echo "\n";
echo cli_get_process_title(), "\n";
--EXPECT--
cli_set_process_title=yes
cli_get_process_title=yes

true
my-worker
