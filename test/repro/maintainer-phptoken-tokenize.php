<?php
// Repro for #6794 — PhpToken::tokenize() OOP token stream (ext/tokenizer/tokenizer.c).

echo 'method_exists: ', method_exists('PhpToken', 'tokenize') ? 'yes' : 'no', "\n";

$tokens = PhpToken::tokenize('<?php echo 1;');
echo 'count: ', count($tokens), "\n";
echo $tokens[1]->id, ' ', $tokens[1]->text, ' line=', $tokens[1]->line, ' pos=', $tokens[1]->pos, "\n";
echo $tokens[1]->getTokenName(), "\n";
echo $tokens[1]->is(T_ECHO) ? "is_echo\n" : "not_echo\n";
echo $tokens[1]->isIgnorable() ? "ignorable\n" : "not_ignorable\n";
echo (string) $tokens[1], "\n";

try {
    PhpToken::tokenize([]);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
