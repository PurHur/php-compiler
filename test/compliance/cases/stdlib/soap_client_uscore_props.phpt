--TEST--
Stdlib: SoapClient underscored option props (#23923, ext/soap/soap.stub.php)
--FILE--
<?php
declare(strict_types=1);

$c = new SoapClient(null, [
    'location' => 'http://127.0.0.1/',
    'uri' => 'http://test/',
    'login' => 'user',
    'password' => 'pass',
    'encoding' => 'UTF-8',
    'classmap' => ['Book' => 'stdClass'],
    'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
    'connection_timeout' => 5,
    'keep_alive' => false,
    'ssl_method' => SOAP_SSL_METHOD_TLS,
    'soap_version' => SOAP_1_2,
    'exceptions' => false,
    'user_agent' => 'UA/1',
]);
echo 'login=', (int) property_exists($c, '_login'), ':', (string) $c->_login, "\n";
echo 'pass=', (int) property_exists($c, '_password'), ':', (string) $c->_password, "\n";
echo 'enc=', (int) property_exists($c, '_encoding'), ':', (string) $c->_encoding, "\n";
echo 'cmap=', (int) property_exists($c, '_classmap'), ':', (is_array($c->_classmap) && ($c->_classmap['Book'] ?? '') === 'stdClass' ? '1' : '0'), "\n";
echo 'feat=', (int) property_exists($c, '_features'), ':', (int) $c->_features, "\n";
echo 'cto=', (int) property_exists($c, '_connection_timeout'), ':', (int) $c->_connection_timeout, "\n";
echo 'ka=', (int) property_exists($c, '_keep_alive'), ':', (int) $c->_keep_alive, "\n";
echo 'ssl=', (int) property_exists($c, '_ssl_method'), ':', (int) $c->_ssl_method, "\n";
echo 'ver=', (int) property_exists($c, '_soap_version'), ':', (int) $c->_soap_version, "\n";
echo 'ex=', (int) property_exists($c, '_exceptions'), ':', (int) $c->_exceptions, "\n";
echo 'ua=', (int) property_exists($c, '_user_agent'), ':', (string) $c->_user_agent, "\n";

$n = new SoapClient(null, ['location' => 'http://127.0.0.1/', 'uri' => 'http://test/']);
echo 'def_login_null=', (int) (null === $n->_login), "\n";
echo 'def_feat_null=', (int) (null === $n->_features), "\n";
echo 'def_ka=', (int) (true === $n->_keep_alive), "\n";
echo 'def_cto=', (int) (0 === $n->_connection_timeout), "\n";
echo 'def_ver=', (int) (SOAP_1_1 === $n->_soap_version), "\n";
echo 'def_ex=', (int) (true === $n->_exceptions), "\n";
?>
--EXPECT--
login=1:user
pass=1:pass
enc=1:UTF-8
cmap=1:1
feat=1:1
cto=1:5
ka=1:0
ssl=1:0
ver=1:2
ex=1:0
ua=1:UA/1
def_login_null=1
def_feat_null=1
def_ka=1
def_cto=1
def_ver=1
def_ex=1
