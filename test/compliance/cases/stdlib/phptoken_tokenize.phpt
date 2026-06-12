--TEST--
stdlib PhpToken::tokenize() — OOP token stream (#6794, ext/tokenizer/tokenizer.c)
--FILE--
<?php
echo method_exists('PhpToken', 'tokenize') ? "method\n" : "no-method\n";
$tokens = PhpToken::tokenize('<?php echo 1;');
echo $tokens[1]->id, "\n";
echo $tokens[1]->text, "\n";
echo $tokens[1]->line, "\n";
echo $tokens[1]->pos, "\n";
echo $tokens[1]->getTokenName(), "\n";
echo $tokens[1]->is(T_ECHO) ? "is_echo\n" : "not_echo\n";
echo $tokens[0]->isIgnorable() ? "open_ignorable\n" : "open_not_ignorable\n";
echo $tokens[1]->__toString(), "\n";
try {
    PhpToken::tokenize([]);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
method
291
echo
1
6
T_ECHO
is_echo
open_ignorable
echo
PhpToken::tokenize(): Argument #1 ($code) must be of type string, array given
