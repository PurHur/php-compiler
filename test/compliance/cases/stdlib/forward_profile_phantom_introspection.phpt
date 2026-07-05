--TEST--
stdlib forward profile — fpow/mb_str_pad/bcmath withheld from introspection (#16086)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$fail = [];
if (function_exists('fpow')) {
    $fail[] = 'fpow';
}
if (function_exists('mb_str_pad')) {
    $fail[] = 'mb_str_pad';
}
if (extension_loaded('bcmath')) {
    $fail[] = 'bcmath';
}
if (function_exists('bcadd')) {
    $fail[] = 'bcadd';
}
if (function_exists('stream_context_set_options')) {
    $fail[] = 'stream_context_set_options';
}
echo [] === $fail ? "ok\n" : 'fail: '.implode(',', $fail)."\n";
echo 'callable=', (int) is_float(fpow(2.0, 3.0)), "\n";
echo 'http_callable=', (int) \is_array(http_get_last_response_headers()), "\n";
$ctx = stream_context_create([]);
echo 'stream_callable=', (int) stream_context_set_options($ctx, ['http' => ['timeout' => 1]]), "\n";
--EXPECT--
ok
callable=1
http_callable=1
stream_callable=1
