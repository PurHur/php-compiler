--TEST--
stdlib: highlight_file/show_source ArgumentCountError wording JIT (#30689)
--FILE--
<?php
foreach ([
    'hf_hi' => static fn () => highlight_file('php://memory', false, 1),
    'ss_hi' => static fn () => show_source('php://memory', false, 1),
    'hf_lo' => static fn () => highlight_file(),
] as $name => $call) {
    try {
        $call();
        echo $name, " NO_THROW\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
$html = highlight_file('php://memory', true);
echo 'ok=', (is_string($html)) ? '1' : '0', "\n";
--EXPECT--
hf_hi ArgumentCountError: highlight_file() expects at most 2 arguments, 3 given
ss_hi ArgumentCountError: show_source() expects at most 2 arguments, 3 given
hf_lo ArgumentCountError: highlight_file() expects at least 1 argument, 0 given
ok=1
