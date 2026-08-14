--TEST--
msgfmt_format_message / MessageFormatter::formatMessage(null) TypeError under strict_types (#29921)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip MessageFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
declare(strict_types=1);
foreach ([
    'proc_locale' => static fn () => msgfmt_format_message(null, 'Hi {0}', [1]),
    'proc_pattern' => static fn () => msgfmt_format_message('en', null, [1]),
    'static_locale' => static fn () => MessageFormatter::formatMessage(null, 'Hi {0}', [1]),
    'static_pattern' => static fn () => MessageFormatter::formatMessage('en', null, [1]),
] as $name => $call) {
    try {
        $r = $call();
        echo $name, ' OK ', var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $name, ' TypeError';
        if (false !== strpos($e->getMessage(), 'null given')) {
            echo ' null';
        }
        if (false !== strpos($e->getMessage(), '($locale)')) {
            echo ' locale';
        }
        if (false !== strpos($e->getMessage(), '($pattern)')) {
            echo ' pattern';
        }
        echo "\n";
    }
}
echo 'ok=', msgfmt_format_message('en', 'Hi {0}', [1]), "\n";
?>
--EXPECT--
proc_locale TypeError null locale
proc_pattern TypeError null pattern
static_locale TypeError null locale
static_pattern TypeError null pattern
ok=Hi 1
