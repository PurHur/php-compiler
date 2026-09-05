<?php
/**
 * #36380 / #34084 — runtime `$obj->$name()` after concat (Parsedown blockContinue shape).
 *
 * Expect (Zend):
 * blockHeader:x
 * blockList:y
 * ok
 */
class Pd
{
    public function blockHeader(string $x): string
    {
        return 'blockHeader:'.$x;
    }

    public function blockList(string $x): string
    {
        return 'blockList:'.$x;
    }

    public function run(string $type, string $arg): string
    {
        $methodName = 'block'.$type;
        return $this->$methodName($arg);
    }
}

$p = new Pd();
echo $p->run('Header', 'x'), "\n";
$m = 'block'.'List';
echo $p->$m('y'), "\n";
echo 'ok';
