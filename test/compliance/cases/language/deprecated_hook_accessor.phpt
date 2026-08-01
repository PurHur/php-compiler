--TEST--
Language: #[\Deprecated] on property hook get/set emits E_USER_DEPRECATED (#26370, Zend/zend_attributes.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
set_error_handler(function (int $n, string $s): bool {
    echo 'DEP:', $s, "\n";
    return true;
});

class GetHook {
    public string $x {
        #[\Deprecated]
        get => 'a';
        set {}
    }
}

class SetHook {
    private string $s = 'a';
    public string $x {
        get => $this->s;
        #[\Deprecated]
        set { $this->s = $value; }
    }
}

class MsgHook {
    public string $x {
        #[\Deprecated(message: 'old', since: '8.4')]
        get => 'a';
        set {}
    }
}

$g = new GetHook;
echo $g->x, "\n";

$s = new SetHook;
$s->x = 'b';
echo $s->x, "\n";

$m = new MsgHook;
echo $m->x, "\n";
--EXPECT--
DEP:Method GetHook::$x::get() is deprecated
a
DEP:Method SetHook::$x::set() is deprecated
b
DEP:Method MsgHook::$x::get() is deprecated since 8.4, old
a
