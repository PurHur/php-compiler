<?php
/**
 * Issue #27263 — AOT PhpToken::tokenize() + getTokenName() match Zend/VM/JIT.
 *
 * @see ext/tokenizer/tokenizer.c PhpToken::getTokenName
 */
$t = PhpToken::tokenize('<?php echo 1;');
echo $t[1]->getTokenName(), "\n";
