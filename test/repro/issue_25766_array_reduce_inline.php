<?php
// Repro #25766: consecutive inline arrays in array_reduce must not share HT temps.
class R
{
    public function add($c, $n)
    {
        return $c + $n;
    }

    public function run()
    {
        echo 'inline=', array_reduce([1, 2], [$this, 'add'], 0), "\n";
        $nums = [1, 2];
        $cb = [$this, 'add'];
        echo 'vars=', array_reduce($nums, $cb, 0), "\n";
    }
}
(new R)->run();
