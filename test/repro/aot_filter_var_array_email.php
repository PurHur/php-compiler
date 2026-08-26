<?php
/**
 * #35016 — AOT filter_var_array() FILTER_VALIDATE_EMAIL must match Zend/VM.
 * php-src: ext/filter/filter.c php_filter_var_array / logical_filters.c
 */
$data = ['email' => 'a@b.com', 'age' => '21', 'bad' => 'nope', 'url' => 'https://example.com'];
$r = filter_var_array($data, [
    'email' => FILTER_VALIDATE_EMAIL,
    'age' => FILTER_VALIDATE_INT,
    'bad' => FILTER_VALIDATE_INT,
    'url' => FILTER_VALIDATE_URL,
]);
echo 'email=', var_export($r['email'], true), "\n";
echo 'age=', var_export($r['age'], true), "\n";
echo 'bad=', var_export($r['bad'], true), "\n";
echo 'url=', var_export($r['url'], true), "\n";
