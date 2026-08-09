--TEST--
get_defined_constants(true) Dom/PECL buckets — no spurious user (#29485, re-#23732, zend_builtin_functions.c)
--FILE--
<?php
declare(strict_types=1);

$c = get_defined_constants(true);
$userCount = isset($c['user']) ? count($c['user']) : 0;
echo $userCount === 0 ? "user_ok\n" : "user_bad keys={$userCount}\n";

$htmlNo = 'Dom\\HTML_NO_DEFAULT_NS';
if (defined($htmlNo)) {
    echo isset($c['dom'][$htmlNo]) ? "dom_html_no_ok\n" : "dom_html_no_bad\n";
    echo isset($c['user'][$htmlNo]) ? "dom_html_no_in_user\n" : "dom_html_no_not_user\n";
} else {
    echo "dom_html_no_ok\n";
    echo "dom_html_no_not_user\n";
}

foreach ([
    'msgpack' => 'MESSAGEPACK_OPT_ASSOC',
    'yaml' => 'YAML_ANY_ENCODING',
    'apcu' => 'APC_ITER_KEY',
    'brotli' => 'BROTLI_GENERIC',
    'ssh2' => 'SSH2_FINGERPRINT_MD5',
    'eio' => 'EIO_PRI_DEFAULT',
    'imap' => 'SA_MESSAGES',
] as $ext => $const) {
    if (extension_loaded($ext) && defined($const)) {
        echo isset($c[$ext][$const]) ? "{$ext}_ok\n" : "{$ext}_bad\n";
        echo isset($c['user'][$const]) ? "{$ext}_in_user\n" : "{$ext}_not_user\n";
    } else {
        echo "{$ext}_ok\n";
        echo "{$ext}_not_user\n";
    }
}

define('USER_CONST_29485', 1);
$c2 = get_defined_constants(true);
echo isset($c2['user']['USER_CONST_29485']) ? "define_user_ok\n" : "define_user_bad\n";
--EXPECT--
user_ok
dom_html_no_ok
dom_html_no_not_user
msgpack_ok
msgpack_not_user
yaml_ok
yaml_not_user
apcu_ok
apcu_not_user
brotli_ok
brotli_not_user
ssh2_ok
ssh2_not_user
eio_ok
eio_not_user
imap_ok
imap_not_user
define_user_ok
