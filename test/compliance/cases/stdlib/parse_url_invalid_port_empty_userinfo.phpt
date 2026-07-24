--TEST--
stdlib parse_url() invalid port → false; empty user/pass keys retained (#22822, php-src url.c)
--FILE--
<?php
foreach ([
    'http://ex.com:port/',
    'http://ex.com:99999/',
    'http://ex.com:100000/',
    'http://[::1]:port/',
] as $u) {
    echo $u, ' => ', var_export(parse_url($u), true), "\n";
}

$parts = parse_url('http://user:@h/');
echo 'user:@ user=', var_export($parts['user'], true), ' pass=', var_export($parts['pass'], true), "\n";
echo 'user:@ PHP_URL_PASS=', var_export(parse_url('http://user:@h/', PHP_URL_PASS), true), "\n";

$parts = parse_url('http://:pass@h/');
echo ':pass user=', var_export($parts['user'], true), ' pass=', var_export($parts['pass'], true), "\n";
echo ':pass PHP_URL_USER=', var_export(parse_url('http://:pass@h/', PHP_URL_USER), true), "\n";

$parts = parse_url('http://@h/');
echo '@only user=', var_export($parts['user'], true), ' pass_set=', array_key_exists('pass', $parts) ? 'yes' : 'no', "\n";

$parts = parse_url('http://ex.com:0/');
echo 'port0=', var_export($parts['port'], true), "\n";
--EXPECT--
http://ex.com:port/ => false
http://ex.com:99999/ => false
http://ex.com:100000/ => false
http://[::1]:port/ => false
user:@ user='user' pass=''
user:@ PHP_URL_PASS=''
:pass user='' pass='pass'
:pass PHP_URL_USER=''
@only user='' pass_set=no
port0=0
