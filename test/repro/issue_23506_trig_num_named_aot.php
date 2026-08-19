<?php
/**
 * #23506 companion — AOT named $num (Reflection is VM/JIT; AOT dispatch separate).
 *
 * log10()/log() AOT helpers are NestedJIT series (#28642) and are covered on VM/JIT,
 * not in this binary (runtime can stall in the helper, independent of named-arg mapping).
 */
echo 'sin=', (int) round(sin(num: M_PI / 2)), "\n";
echo 'cos=', (int) round(cos(num: 0)), "\n";
echo 'tan=', (int) round(tan(num: 0)), "\n";
echo 'asin=', (int) asin(num: 0), "\n";
echo 'acos=', (int) acos(num: 1), "\n";
echo 'atan=', (int) atan(num: 0), "\n";
echo 'exp=', (int) exp(num: 0), "\n";
echo 'sinh=', (int) sinh(num: 0), "\n";
echo 'cosh=', (int) cosh(num: 0), "\n";
echo 'tanh=', (int) tanh(num: 0), "\n";
