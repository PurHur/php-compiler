--TEST--
stdlib: highlight_string/get_meta_tags ArgumentCountError wording (#30723)
--FILE--
<?php
foreach ([
    'hs_hi' => static fn () => highlight_string('<?php', true, 3),
    'hs_lo' => static fn () => highlight_string(),
    'gmt_hi' => static fn () => get_meta_tags('/etc/hosts', true, 3),
    'gmt_lo' => static fn () => get_meta_tags(),
] as $name => $call) {
    try {
        $call();
        echo $name, " NO_THROW\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
$html = highlight_string('<?php echo 1;', true);
echo 'ok_hs=', (is_string($html) && '' !== $html) ? '1' : '0', "\n";
$tags = get_meta_tags('test/compliance/cases/stdlib/get_meta_tags_fixture.html', true);
echo 'ok_gmt=', (is_array($tags) && isset($tags['author']) && 'me' === $tags['author']) ? '1' : '0', "\n";
--EXPECT--
hs_hi ArgumentCountError: highlight_string() expects at most 2 arguments, 3 given
hs_lo ArgumentCountError: highlight_string() expects at least 1 argument, 0 given
gmt_hi ArgumentCountError: get_meta_tags() expects at most 2 arguments, 3 given
gmt_lo ArgumentCountError: get_meta_tags() expects at least 1 argument, 0 given
ok_hs=1
ok_gmt=1
