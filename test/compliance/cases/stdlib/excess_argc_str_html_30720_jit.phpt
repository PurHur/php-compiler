--TEST--
stdlib JIT: str_word_count/htmlspecialchars_decode/get_html_translation_table ArgumentCountError wording (#30720)
--FILE--
<?php
foreach ([
    'swc_hi' => static fn () => str_word_count('a', 0, '', 4),
    'swc_lo' => static fn () => str_word_count(),
    'hsd_hi' => static fn () => htmlspecialchars_decode('&lt;', ENT_QUOTES, 3),
    'hsd_lo' => static fn () => htmlspecialchars_decode(),
    'ghtt_hi' => static fn () => get_html_translation_table(HTML_SPECIALCHARS, ENT_QUOTES, 'UTF-8', 4),
] as $name => $call) {
    try {
        $call();
        echo $name, " NO_THROW\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
$swc = str_word_count('hello world');
echo 'ok_swc=', (2 === $swc) ? '1' : '0', "\n";
$hsd = htmlspecialchars_decode('&lt;');
echo 'ok_hsd=', ('<' === $hsd) ? '1' : '0', "\n";
$ghtt = get_html_translation_table();
echo 'ok_ghtt=', (is_array($ghtt) && isset($ghtt['<'])) ? '1' : '0', "\n";
--EXPECT--
swc_hi ArgumentCountError: str_word_count() expects at most 3 arguments, 4 given
swc_lo ArgumentCountError: str_word_count() expects at least 1 argument, 0 given
hsd_hi ArgumentCountError: htmlspecialchars_decode() expects at most 2 arguments, 3 given
hsd_lo ArgumentCountError: htmlspecialchars_decode() expects at least 1 argument, 0 given
ghtt_hi ArgumentCountError: get_html_translation_table() expects at most 3 arguments, 4 given
ok_swc=1
ok_hsd=1
ok_ghtt=1
