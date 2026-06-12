<?php
// AOT compile-only (#6794): PhpToken::tokenize() VM builtin class method.
$tokens = PhpToken::tokenize('<?php echo 1;');
var_export($tokens[1]->id === T_ECHO);
var_export($tokens[1]->text === 'echo');
var_export($tokens[1]->getTokenName() === 'T_ECHO');
