--TEST--
func_num_args/func_get_args bind nested zero-arg callee not caller (#25896)
--FILE--
<?php
function fna($a = null, $b = null) {
    return func_num_args();
}
function fga($a = null, $b = null) {
    return func_get_args();
}
function wrapper($x, $y) {
    return fna();
}
function wrap_fga($x, $y) {
    return fga();
}
echo fna(), "\n";
echo wrapper(1, 2), "\n";
echo json_encode(fga()), "\n";
echo json_encode(wrap_fga(1, 2)), "\n";
echo fna(9), "\n";
--EXPECT--
0
0
[]
[]
1
