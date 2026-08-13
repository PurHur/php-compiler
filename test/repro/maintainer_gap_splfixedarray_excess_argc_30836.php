<?php
function t(string $label, callable $fn): void
{
    try {
        $fn();
        echo $label, ": ACCEPTED\n";
    } catch (ArgumentCountError $e) {
        echo $label, ': ', $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo $label, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}

t('fromArray', fn () => SplFixedArray::fromArray([1], true, 'x'));
$a = SplFixedArray::fromArray([1, 2]);
t('toArray', fn () => $a->toArray('x'));
$b = new SplFixedArray(2);
t('setSize', fn () => $b->setSize(3, 'x'));
t('getSize', fn () => $b->getSize('x'));
$ok = SplFixedArray::fromArray([7]);
echo 'ok_fromArray: ', $ok[0], "\n";
echo 'ok_toArray: ', count($ok->toArray()), "\n";
$b->setSize(1);
echo 'ok_setSize: ', $b->getSize(), "\n";
