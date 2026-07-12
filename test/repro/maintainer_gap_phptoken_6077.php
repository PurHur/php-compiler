<?php

declare(strict_types=1);

/**
 * Issue #6077 — PhpToken OOP API parity (ext/tokenizer/tokenizer.c).
 *
 * @see Zend/php-src ext/tokenizer/tokenizer.c PhpToken::{tokenize,is,getTokenName,isIgnorable}
 */

echo 'class_exists=', var_export(class_exists('PhpToken'), true), "\n";
echo 'method_tokenize=', var_export(method_exists('PhpToken', 'tokenize'), true), "\n";

$tokens = PhpToken::tokenize('<?php echo 1;');
echo 'echo_id=', $tokens[1]->id, "\n";
echo 'echo_name=', $tokens[1]->getTokenName(), "\n";
echo 'is_echo=', var_export($tokens[1]->is(T_ECHO), true), "\n";
echo 'open_ignorable=', var_export($tokens[0]->isIgnorable(), true), "\n";
echo 'echo_text=', (string) $tokens[1], "\n";
