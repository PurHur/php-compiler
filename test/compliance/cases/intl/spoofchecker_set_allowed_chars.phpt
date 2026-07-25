--TEST--
Spoofchecker::setAllowedChars() UnicodeSet restrict (#20823, #23157)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip Spoofchecker withheld until extension_loaded(\'intl\') (#19670/#20823)';
}
if (!\PHPCompiler\CompilerVersion::supportsSpoofcheckerSetAllowedChars()) {
    echo 'skip setAllowedChars requires PHP_COMPILER_PROFILE=8.4 (#23157)';
}
?>
--FILE--
<?php
$s = new Spoofchecker();
echo 'method=', (int) method_exists($s, 'setAllowedChars'), "\n";
echo 'ignore=', Spoofchecker::IGNORE_SPACE, "\n";
$s->setAllowedChars('[a-z0-9]');
echo 'hello=', (int) $s->isSuspicious('hello'), "\n";
echo 'accent=', (int) $s->isSuspicious("h\xC3\xA9llo"), "\n";
try {
    $s->setAllowedChars('a-z');
    echo "bad_pattern=no\n";
} catch (ValueError $e) {
    echo "bad_pattern=yes\n";
}
try {
    $s->setAllowedChars('[a-z]', 99);
    echo "bad_opts=no\n";
} catch (ValueError $e) {
    echo "bad_opts=yes\n";
}
?>
--EXPECT--
method=1
ignore=1
hello=0
accent=1
bad_pattern=yes
bad_opts=yes
