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

$d = new DateTime('2020-01-01');
$d2 = new DateTime('2021-01-01');
$i = new DateInterval('P1D');
$z = new DateTimeZone('UTC');

t('format', fn () => $d->format('Y', 'x'));
t('modify', fn () => $d->modify('+1 day', 'x'));
t('setDate', fn () => $d->setDate(2021, 2, 3, 'x'));
t('setTime5', fn () => $d->setTime(1, 2, 3, 0, 'x'));
t('getTimestamp', fn () => $d->getTimestamp('x'));
t('add', fn () => $d->add($i, 'x'));
t('sub', fn () => $d->sub($i, 'x'));
t('diff', fn () => $d->diff($d2, false, 'x'));
t('getName', fn () => $z->getName('x'));
t('getOffset', fn () => $z->getOffset($d, 'x'));
t('getLocation', fn () => $z->getLocation('x'));
t('intervalFormat', fn () => $i->format('%d', 'x'));
echo 'ok_format: ', $d->format('Y'), "\n";
