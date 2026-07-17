<?php
$inner = new RecursiveArrayIterator([1, [2, 3], 4, [5, 6]]);
$it = new class($inner) extends RecursiveFilterIterator {
    public function accept(): bool
    {
        $c = $this->current();

        return is_array($c) ? true : ($c % 2 === 0);
    }
};
echo 'hasChildren=', method_exists($it, 'hasChildren') ? 'Y' : 'N', "\n";
$rii = new RecursiveIteratorIterator($it);
echo 'RII=', implode(',', iterator_to_array($rii, false)), "\n";
