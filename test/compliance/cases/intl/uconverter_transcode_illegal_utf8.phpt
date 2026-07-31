--TEST--
UConverter::transcode/convert illegal UTF-8 → U+FFFD (#25203, ext/intl/converter)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip UConverter withheld until extension_loaded(\'intl\') (#19670/#6171)';
}
?>
--FILE--
<?php
$cases = [
    "caf\x80",
    "a\xC0\x80b",
    "\xED\xA0\x80",
    "\xE0\x80\x80",
    "\xF0\x80\x80\x80",
    "\xF4\x90\x80\x80",
    "\xF5\x80\x80\x80",
    "\xC2\x20",
    "ok\xFF",
    "\xE2\x82\xAC",
];
foreach ($cases as $t) {
    echo bin2hex($t), ' tc=', bin2hex(UConverter::transcode($t, 'UTF-8', 'UTF-8')), "\n";
}
$c = new UConverter('UTF-8', 'UTF-8');
echo 'convert caf80=', bin2hex($c->convert("caf\x80")), "\n";
echo 'convert c080=', bin2hex($c->convert("a\xC0\x80b")), "\n";
--EXPECT--
63616680 tc=636166efbfbd
61c08062 tc=61efbfbdefbfbd62
eda080 tc=efbfbdefbfbdefbfbd
e08080 tc=efbfbdefbfbdefbfbd
f0808080 tc=efbfbdefbfbdefbfbdefbfbd
f4908080 tc=efbfbdefbfbdefbfbdefbfbd
f5808080 tc=efbfbdefbfbdefbfbdefbfbd
c220 tc=efbfbd20
6f6bff tc=6f6befbfbd
e282ac tc=e282ac
convert caf80=636166efbfbd
convert c080=61efbfbdefbfbd62
