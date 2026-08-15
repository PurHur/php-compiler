--TEST--
stdlib forward profile — fpow/bcround advertised; IEEE phantoms withheld (#28565, #16677, #16086)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$fail = [];
foreach (['fpow', 'bcround'] as $fn) {
    if (!function_exists($fn)) {
        $fail[] = $fn;
    }
}
foreach (['fmin', 'fmax', 'fadd', 'fsub', 'fmul', 'nextafter'] as $fn) {
    if (function_exists($fn)) {
        $fail[] = $fn;
    }
}
if (extension_loaded('bcmath')) {
    $fail[] = 'bcmath';
}
if (function_exists('bcadd')) {
    $fail[] = 'bcadd';
}
foreach (['http_get_last_response_headers', 'http_clear_last_response_headers'] as $fn) {
    if (!function_exists($fn)) {
        $fail[] = $fn;
    }
}
if (function_exists('get_last_response_headers')) {
    $fail[] = 'get_last_response_headers';
}
if (!function_exists('stream_context_set_options')) {
    $fail[] = 'stream_context_set_options';
}
echo [] === $fail ? "ok\n" : 'fail: '.implode(',', $fail)."\n";
echo 'callable=', (int) is_float(fpow(2.0, 3.0)), "\n";
echo 'http_callable=', (int) (null === http_get_last_response_headers()), "\n";
$ctx = stream_context_create([]);
echo 'stream_callable=', (int) stream_context_set_options($ctx, ['http' => ['timeout' => 1]]), "\n";
echo 'bcround_callable=', (int) ('1.23' === bcround('1.234', 2)), "\n";
--EXPECT--
ok
callable=1
http_callable=1
stream_callable=1
bcround_callable=1
