--TEST--
Language: unserialize(serialize(Exception)) restores getMessage()/getCode() (issue #26673)
--FILE--
<?php
function dump_throwable(Throwable $t): void
{
    echo get_class($t), ':', $t->getMessage(), ':', $t->getCode(), "\n";
}

dump_throwable(unserialize(serialize(new Exception('hi', 7))));
dump_throwable(unserialize(serialize(new Error('err', 3))));
dump_throwable(unserialize(serialize(new RuntimeException('rt', 9))));
dump_throwable(unserialize(serialize(new InvalidArgumentException('ia', 2))));

class ExtraException extends Exception
{
    public string $extra = 'x';
}

$e = new ExtraException('ex', 5);
$e->extra = 'keep';
$u = unserialize(serialize($e));
dump_throwable($u);
echo $u->extra, "\n";
--EXPECT--
Exception:hi:7
Error:err:3
RuntimeException:rt:9
InvalidArgumentException:ia:2
ExtraException:ex:5
keep
