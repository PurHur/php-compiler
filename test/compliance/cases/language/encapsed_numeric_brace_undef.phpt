--TEST--
Language: encapsed ${1} undefined — E_WARNING like named brace (Zend/zend_compile.c, #22776)
--FILE--
<?php
function warn_capture(int $errno, string $message): bool
{
    if (E_WARNING === $errno) {
        echo 'W:', $message, "\n";
    }

    return true;
}
set_error_handler('warn_capture');

$u = "${1}";
echo 'undef=[', $u, "]\n";

${1} = 'ONE';
$d = "${1}";
echo 'defined=[', $d, "]\n";

$n = "${missing}";
echo 'named=[', $n, "]\n";
--EXPECT--
W:Undefined variable $1
undef=[]
defined=[ONE]
W:Undefined variable $missing
named=[]
