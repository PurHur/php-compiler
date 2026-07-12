<?php

class Logger {}

class S {
    public Logger $l = new Logger();
}

$o = new S();
echo $o->l instanceof Logger ? "ok\n" : "no\n";
