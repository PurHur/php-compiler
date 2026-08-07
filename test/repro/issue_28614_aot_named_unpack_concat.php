<?php
/**
 * #28614 — AOT named unpack + encapsed/concat must not segfault.
 *
 * Root cause: SimpleXML user-script AOT treated every TYPE_VALUE (including
 * script-global scalars) as an SXE and emitted unconditional __value__readObject
 * on concat/encapsed echo. Named unpack itself was already correct.
 *
 * @differential-repeat: 10
 */
function f($a, $b)
{
    echo "$a-$b\n";
}
f(...['b' => 2, 'a' => 1]);

$x = 'x';
echo $x."y\n";
