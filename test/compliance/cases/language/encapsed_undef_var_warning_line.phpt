--TEST--
Language: encapsed/heredoc Undefined variable Warning cites user site (#32034, Zend/zend_compile.c)
--FILE--
<?php
function warn_line(int $errno, string $message, string $file, int $line): bool
{
    if (E_WARNING === $errno) {
        echo 'W:', $message, '@', $line, "\n";
    }

    return true;
}
set_error_handler('warn_line');

echo "x=$missing_dq\n";
$s = "a$missing_asg b";
$s = "a{$missing_brace} b";
echo "${missing_dollar}\n";
$h = <<<EOT
a$missing_heredoc
EOT;
echo $missing_plain;
echo "done\n";
--EXPECT--
W:Undefined variable $missing_dq@12
x=
W:Undefined variable $missing_asg@13
W:Undefined variable $missing_brace@14
W:Undefined variable $missing_dollar@15

W:Undefined variable $missing_heredoc@17
W:Undefined variable $missing_plain@19
done
