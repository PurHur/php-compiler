--TEST--
Stdlib: set_error_handler() closure + callable forms (VM, #4309)
--FILE--
<?php
$hits = 0;

set_error_handler(function (int $no, string $str, string $file, int $line) use (&$hits): bool {
    $hits++;
    echo "closure:$no:$str\n";
    return true;
});
trigger_error('probe', E_USER_NOTICE);
echo "hits:$hits\n";

class InvokableErrHandler {
    public int $hits = 0;
    public function __invoke(int $no, string $str, string $file, int $line): bool {
        $this->hits++;
        echo "invokable:$no:$str\n";
        return true;
    }
}
$obj = new InvokableErrHandler();
set_error_handler($obj);
trigger_error('probe2', E_USER_NOTICE);
echo "obj_hits:{$obj->hits}\n";

class ArrayCallableErrHandler {
    public int $hits = 0;
    public function handle(int $no, string $str, string $file, int $line): bool {
        $this->hits++;
        echo "array:$no:$str\n";
        return true;
    }
}
$obj2 = new ArrayCallableErrHandler();
set_error_handler([$obj2, 'handle']);
trigger_error('probe3', E_USER_NOTICE);
echo "obj2_hits:{$obj2->hits}\n";

// Mask honored: should not invoke for E_USER_WARNING.
set_error_handler(function () use (&$hits): bool {
    $hits++;
    echo "mask-hit\n";
    return true;
}, E_USER_NOTICE);
trigger_error('nope', E_USER_WARNING);
echo "hits2:$hits\n";

restore_error_handler();
restore_error_handler();
restore_error_handler();
restore_error_handler();
echo "done\n";
--EXPECT--
closure:1024:probe
hits:1
invokable:1024:probe2
obj_hits:1
array:1024:probe3
obj2_hits:1
hits2:1
done

