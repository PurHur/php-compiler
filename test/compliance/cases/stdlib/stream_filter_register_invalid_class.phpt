--TEST--
stdlib stream_filter_register() invalid class — E_WARNING + true, name registered (ext/standard/streams.c, #13733)
--FILE--
<?php
class BadFilter {
    public function filter($in, $out, &$consumed, $closing) {
        return PSFS_PASS_ON;
    }
}
$name = 'bad.invalid.' . getmypid();
$ok = @stream_filter_register($name, BadFilter::class);
$filters = stream_get_filters();
echo 'ok=' . var_export($ok, true) . "\n";
echo 'in_list=' . var_export(in_array($name, $filters, true), true) . "\n";
--EXPECT--
ok=true
in_list=true
