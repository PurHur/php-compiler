<?php
/**
 * Repro #26671 — TOKEN_PARSE must throw ParseError on unclosed constructs
 * (php-src ext/tokenizer/tokenizer.c / Zend/zend_language_scanner.l).
 */
$cases = [
    '<?php class X {',
    '<?php function(',
    '<?php match(1) {',
    '<?php echo 1;',
];
foreach ($cases as $src) {
    try {
        $tokens = token_get_all($src, TOKEN_PARSE);
        echo 'ok:', count($tokens), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
try {
    PhpToken::tokenize('<?php class X {', TOKEN_PARSE);
    echo "phptoken_ok\n";
} catch (Throwable $e) {
    echo 'phptoken:', get_class($e), ':', $e->getMessage(), "\n";
}
