<?php
/**
 * #36380 — PropertyHooks must not rewrite `$this->{"meth$x"}()` (Parsedown / SourceBundler).
 *
 * Expect (Zend / VM): ok
 */
class C
{
    public function run($t)
    {
        return $this->{"block$t"}();
    }

    protected function blockFoo()
    {
        return 'ok';
    }
}

$c = new C();
echo $c->run('Foo'), "\n";
