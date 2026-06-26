--TEST--
stdlib highlight_file()/show_source() php://memory — no E_WARNING (#12095, ext/standard/url.c)
--FILE--
<?php
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
$expected = '<code><span style="color: #000000">'."\n".'</span>'."\n".'</code>';
$r = highlight_file('php://memory', true);
echo $r === $expected ? 'highlight_ok' : 'highlight_bad', "\n";
$highlightWarnings = count($warnings);
$warnings = [];
$s = show_source('php://memory', true);
echo $s === $expected ? 'show_source_ok' : 'show_source_bad', "\n";
echo 'warnings=', $highlightWarnings + count($warnings), "\n";
--EXPECT--
highlight_ok
show_source_ok
warnings=0
