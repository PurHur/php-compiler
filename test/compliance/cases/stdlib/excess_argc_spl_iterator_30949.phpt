--TEST--
stdlib: SPL iterator wrappers ArgumentCountError (#30949)
--FILE--
<?php
class Issue30949RI implements RecursiveIterator
{
    private int $i = 0;
    public function current(): mixed { return [$this->i]; }
    public function key(): mixed { return $this->i; }
    public function next(): void { $this->i++; }
    public function rewind(): void { $this->i = 0; }
    public function valid(): bool { return $this->i < 2; }
    public function hasChildren(): bool { return true; }
    public function getChildren(): RecursiveIterator { return new self(); }
}
class Issue30949RFI extends RecursiveFilterIterator
{
    public function accept(): bool { return true; }
}
$ii = new IteratorIterator(new ArrayIterator([1]));
$li = new LimitIterator(new ArrayIterator([1, 2, 3]), 0, 1);
$li->rewind();
$ai = new AppendIterator();
$nr = new NoRewindIterator(new ArrayIterator([1]));
$inf = new InfiniteIterator(new ArrayIterator([1]));
$rx = new RegexIterator(new ArrayIterator(['a']), '/a/');
$ei = new EmptyIterator();
$pi = new ParentIterator(new Issue30949RI());
$pi->rewind();
$rfi = new Issue30949RFI(new Issue30949RI());
$rfi->rewind();
foreach ([
    'ii' => static fn () => $ii->getInnerIterator(1),
    'pos' => static fn () => $li->getPosition(1),
    'ligi' => static fn () => $li->getInnerIterator(1),
    'arr' => static fn () => $ai->getArrayIterator(1),
    'nr' => static fn () => $nr->getInnerIterator(1),
    'inf' => static fn () => $inf->getInnerIterator(1),
    'rx' => static fn () => $rx->getRegex(1),
    'er' => static fn () => $ei->rewind(1),
    'pi' => static fn () => $pi->getChildren(1),
    'rfi' => static fn () => $rfi->getChildren(1),
] as $name => $call) {
    try {
        $r = $call();
        echo $name, ' ', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
$okIi = $ii->getInnerIterator();
$okPos = $li->getPosition();
$okArr = $ai->getArrayIterator();
$okRx = $rx->getRegex();
$ei->rewind();
echo 'ok=', (
    $okIi instanceof ArrayIterator
    && is_int($okPos)
    && $okArr instanceof ArrayIterator
    && is_string($okRx)
) ? '1' : '0', "\n";
--EXPECT--
ii ArgumentCountError: IteratorIterator::getInnerIterator() expects exactly 0 arguments, 1 given
pos ArgumentCountError: LimitIterator::getPosition() expects exactly 0 arguments, 1 given
ligi ArgumentCountError: IteratorIterator::getInnerIterator() expects exactly 0 arguments, 1 given
arr ArgumentCountError: AppendIterator::getArrayIterator() expects exactly 0 arguments, 1 given
nr ArgumentCountError: IteratorIterator::getInnerIterator() expects exactly 0 arguments, 1 given
inf ArgumentCountError: IteratorIterator::getInnerIterator() expects exactly 0 arguments, 1 given
rx ArgumentCountError: RegexIterator::getRegex() expects exactly 0 arguments, 1 given
er ArgumentCountError: EmptyIterator::rewind() expects exactly 0 arguments, 1 given
pi ArgumentCountError: RecursiveFilterIterator::getChildren() expects exactly 0 arguments, 1 given
rfi ArgumentCountError: RecursiveFilterIterator::getChildren() expects exactly 0 arguments, 1 given
ok=1
