--TEST--
Language: child-scope ?? on other-instance parent private is silent default (#29503, zend_object_handlers.c)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    echo "WARN: $str\n";
    return true;
});

class ParentPriv
{
    private $x = 1;
}

class ChildPriv extends ParentPriv
{
    public function probe(object $o): void
    {
        echo 'isset=';
        var_export(isset($o->x));
        echo "\n";
        echo 'coalesce=';
        var_export($o->x ?? 'd');
        echo "\n";
        echo 'coalesce_this=';
        var_export($this->x ?? 'd');
        echo "\n";
        try {
            echo 'direct=' . var_export($o->x, true) . "\n";
        } catch (Throwable $e) {
            echo 'direct_err=' . get_class($e) . ':' . $e->getMessage() . "\n";
        }
    }
}

(new ChildPriv())->probe(new ParentPriv());
?>
--EXPECT--
isset=false
coalesce='d'
coalesce_this='d'
direct_err=Error:Cannot access private property ParentPriv::$x
