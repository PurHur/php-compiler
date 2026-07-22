--TEST--
session SessionHandlerInterface / SessionIdInterface / SessionUpdateTimestampHandlerInterface method tables (#22262, ext/session/session.stub.php)
--FILE--
<?php
foreach ([
    'SessionHandlerInterface',
    'SessionIdInterface',
    'SessionUpdateTimestampHandlerInterface',
] as $c) {
    $names = [];
    foreach ((new ReflectionClass($c))->getMethods() as $m) {
        $names[] = $m->getName();
    }
    sort($names);
    echo $c, '=', implode(',', $names), "\n";
    $ifaces = (new ReflectionClass($c))->getInterfaceNames();
    sort($ifaces);
    echo $c, '_extends=', implode(',', $ifaces), "\n";
}

class TestHandler22262 implements SessionHandlerInterface
{
    public function open($path, $name)
    {
        return true;
    }

    public function close()
    {
        return true;
    }

    public function read($id)
    {
        return '';
    }

    public function write($id, $data)
    {
        return true;
    }

    public function destroy($id)
    {
        return true;
    }

    public function gc($max_lifetime)
    {
        return 0;
    }
}

$x = new TestHandler22262();
echo 'implements=', $x instanceof SessionHandlerInterface ? 'Y' : 'N', "\n";
echo 'method_exists_open=', method_exists('SessionHandlerInterface', 'open') ? 'Y' : 'N', "\n";
echo 'method_exists_create_sid=', method_exists('SessionIdInterface', 'create_sid') ? 'Y' : 'N', "\n";
echo 'method_exists_validateId=', method_exists('SessionUpdateTimestampHandlerInterface', 'validateId') ? 'Y' : 'N', "\n";
?>
--EXPECT--
SessionHandlerInterface=close,destroy,gc,open,read,write
SessionHandlerInterface_extends=
SessionIdInterface=create_sid
SessionIdInterface_extends=
SessionUpdateTimestampHandlerInterface=updateTimestamp,validateId
SessionUpdateTimestampHandlerInterface_extends=
implements=Y
method_exists_open=Y
method_exists_create_sid=Y
method_exists_validateId=Y
