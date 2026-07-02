--TEST--
stdlib ini_get() misc PHP_INI_ALL directives — Zend CLI defaults (#14844, main/php_ini.c)
--FILE--
<?php
$expected = [
    'allow_url_fopen' => '1',
    'allow_url_include' => '',
    'default_socket_timeout' => '60',
    'auto_detect_line_endings' => '0',
    'default_mimetype' => 'text/html',
    'variables_order' => 'GPCS',
    'request_order' => 'GP',
    'arg_separator.output' => '&',
];
foreach ($expected as $key => $want) {
    $got = ini_get($key);
    if ($got !== $want) {
        echo "fail: {$key}=" . var_export($got, true) . "\n";
        exit(1);
    }
}
echo "ok\n";
--EXPECT--
ok
