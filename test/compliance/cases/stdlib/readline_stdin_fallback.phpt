--TEST--
stdlib readline() stdin fallback without host ext/readline (#6216)
--FILE--
<?php
enum ReadlinePrompt: string
{
    case Arrow = '>';
}
try {
    $line = readline(ReadlinePrompt::Arrow);
    echo "enum_no_error\n";
} catch (TypeError) {
    echo "enum_type_error\n";
}
?>
--EXPECT--
enum_type_error
