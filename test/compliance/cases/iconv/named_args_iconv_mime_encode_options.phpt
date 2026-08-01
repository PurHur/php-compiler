--TEST--
iconv_mime_encode named arguments use options (VM, issue #24567)
--FILE--
<?php
$opts = ['scheme' => 'Q', 'input-charset' => 'UTF-8', 'output-charset' => 'UTF-8'];
echo iconv_mime_encode(field_name: 'Subject', field_value: 'test', options: $opts), PHP_EOL;
$rf = new ReflectionFunction('iconv_mime_encode');
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), PHP_EOL;
}
try {
    iconv_mime_encode(field_name: 'Subject', field_value: 'test', preference: $opts);
    echo "preference_accepted\n";
} catch (Error $e) {
    echo 'preference_rejected', PHP_EOL;
}
--EXPECT--
Subject: =?UTF-8?Q?test?=
field_name
field_value
options
preference_rejected
