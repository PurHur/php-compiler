--TEST--
UConverter setSourceEncoding/setDestinationEncoding (#20881)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip UConverter withheld until extension_loaded(\'intl\') (#19670/#20881)';
}
?>
--FILE--
<?php
$c = new UConverter('UTF-8', 'ISO-8859-1');
echo 'init_src=', $c->getSourceEncoding(), "\n";
echo 'init_dst=', $c->getDestinationEncoding(), "\n";
foreach (['setSourceEncoding', 'setDestinationEncoding'] as $m) {
    echo $m, '=', method_exists($c, $m) ? 'yes' : 'no', "\n";
}
var_dump($c->setSourceEncoding('utf-8'));
echo 'src=', $c->getSourceEncoding(), "\n";
var_dump($c->setDestinationEncoding('latin1'));
echo 'dst=', $c->getDestinationEncoding(), "\n";
var_dump($c->setSourceEncoding('not-a-real-encoding-xyz'));
echo 'bad_src=', var_export($c->getSourceEncoding(), true), "\n";
echo 'bad_err=', $c->getErrorCode(), "\n";
echo 'bad_msg_has=', (int) str_contains($c->getErrorMessage(), 'U_FILE_ACCESS_ERROR'), "\n";
?>
--EXPECT--
init_src=ISO-8859-1
init_dst=UTF-8
setSourceEncoding=yes
setDestinationEncoding=yes
bool(true)
src=UTF-8
bool(true)
dst=ISO-8859-1
bool(false)
bad_src='UTF-8'
bad_err=4
bad_msg_has=1
