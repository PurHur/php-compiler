<?php
/**
 * Differential: runtime `$obj->$name()` after concat (#36380 / #34084).
 *
 * @differential-repeat: 3
 */
class Dyn
{
    public function alpha(string $x): string
    {
        return 'A:'.$x;
    }

    public function beta(string $x): string
    {
        return 'B:'.$x;
    }

    public function call(string $which, string $arg): string
    {
        $name = $which;
        return $this->$name($arg);
    }
}

$d = new Dyn();
echo $d->call('alpha', '1'), "\n";
$m = 'be'.'ta';
echo $d->$m('2'), "\n";
