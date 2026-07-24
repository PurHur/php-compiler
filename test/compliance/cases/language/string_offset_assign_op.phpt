--TEST--
Language: assign-op on string offsets throws Error (zend_execute.c, #22897)
--FILE--
<?php
$ops = ['+=', '-=', '*=', '/=', '%=', '**=', '.=', '&=', '|=', '^=', '<<=', '>>='];
foreach ($ops as $op) {
    try {
        $s = 'a';
        switch ($op) {
            case '+=':
                $s[0] += 1;
                break;
            case '-=':
                $s[0] -= 1;
                break;
            case '*=':
                $s[0] *= 1;
                break;
            case '/=':
                $s[0] /= 1;
                break;
            case '%=':
                $s[0] %= 1;
                break;
            case '**=':
                $s[0] **= 1;
                break;
            case '.=':
                $s[0] .= 'x';
                break;
            case '&=':
                $s[0] &= 1;
                break;
            case '|=':
                $s[0] |= 1;
                break;
            case '^=':
                $s[0] ^= 1;
                break;
            case '<<=':
                $s[0] <<= 1;
                break;
            case '>>=':
                $s[0] >>= 1;
                break;
        }
        echo $op, ':ok ', $s, "\n";
    } catch (\Throwable $e) {
        echo $op, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
// Simple assign and array compound assign remain valid.
$s = 'ab';
$s[0] = 'Z';
echo 'assign:', $s, "\n";
$a = [1];
$a[0] += 2;
echo 'array:', $a[0], "\n";
?>
--EXPECT--
+=:Error:Cannot use assign-op operators with string offsets
-=:Error:Cannot use assign-op operators with string offsets
*=:Error:Cannot use assign-op operators with string offsets
/=:Error:Cannot use assign-op operators with string offsets
%=:Error:Cannot use assign-op operators with string offsets
**=:Error:Cannot use assign-op operators with string offsets
.=:Error:Cannot use assign-op operators with string offsets
&=:Error:Cannot use assign-op operators with string offsets
|=:Error:Cannot use assign-op operators with string offsets
^=:Error:Cannot use assign-op operators with string offsets
<<=:Error:Cannot use assign-op operators with string offsets
>>=:Error:Cannot use assign-op operators with string offsets
assign:Zb
array:3
