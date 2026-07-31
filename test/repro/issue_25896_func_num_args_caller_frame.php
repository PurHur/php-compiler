<?php
/**
 * #25896 — func_num_args()/func_get_args()/func_get_arg() bind the callee frame,
 * not an outer caller that passed arguments into a zero-arg nested call.
 */
function fna($a = null, $b = null)
{
    return func_num_args();
}
function fga($a = null, $b = null)
{
    return func_get_args();
}
function fga0($a = null, $b = null)
{
    try {
        return (string) func_get_arg(0);
    } catch (Throwable $e) {
        return 'E:'.get_class($e);
    }
}
function wrapper($x, $y)
{
    return fna();
}
function wrap_fga($x, $y)
{
    return fga();
}
function wrap_fga0($x, $y)
{
    return fga0();
}
function mid($a)
{
    return fna();
}
function outer($a, $b, $c)
{
    return mid($a);
}

echo 'fna_direct=', fna(), "\n";
echo 'fna_nested=', wrapper(1, 2), "\n";
echo 'fga_direct=', json_encode(fga()), "\n";
echo 'fga_nested=', json_encode(wrap_fga(1, 2)), "\n";
echo 'fga0_nested=', wrap_fga0(1, 2), "\n";
echo 'mid_nested=', outer(1, 2, 3), "\n";
echo 'fna_extra=', fna(1, 2, 3), "\n";
