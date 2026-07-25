--TEST--
Reflection* serialize()/unserialize() reject (issue #23087, ext/reflection/php_reflection.stub.php)
--FILE--
<?php
#[Attribute]
class A {}
#[A]
class C {
    function m($p) {}
}

try {
    serialize(new ReflectionClass('stdClass'));
    echo "ReflectionClass serialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    unserialize('O:15:"ReflectionClass":0:{}');
    echo "ReflectionClass unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    serialize(new ReflectionFunction('strlen'));
    echo "ReflectionFunction serialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    unserialize('O:18:"ReflectionFunction":0:{}');
    echo "ReflectionFunction unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    serialize(new ReflectionMethod(C::class, 'm'));
    echo "ReflectionMethod serialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    unserialize('O:16:"ReflectionMethod":0:{}');
    echo "ReflectionMethod unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    serialize((new ReflectionFunction('strlen'))->getParameters()[0]);
    echo "ReflectionParameter serialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    unserialize('O:19:"ReflectionParameter":0:{}');
    echo "ReflectionParameter unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    serialize((new ReflectionClass(C::class))->getAttributes()[0]);
    echo "ReflectionAttribute serialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    unserialize('O:19:"ReflectionAttribute":0:{}');
    echo "ReflectionAttribute unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    serialize(new ReflectionObject(new stdClass));
    echo "ReflectionObject serialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    unserialize('O:16:"ReflectionObject":0:{}');
    echo "ReflectionObject unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    serialize(new ReflectionProperty(Exception::class, 'message'));
    echo "ReflectionProperty serialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    unserialize('O:18:"ReflectionProperty":0:{}');
    echo "ReflectionProperty unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    serialize(new ReflectionClassConstant(DateTime::class, 'ATOM'));
    echo "ReflectionClassConstant serialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    unserialize('O:23:"ReflectionClassConstant":0:{}');
    echo "ReflectionClassConstant unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    serialize(new ReflectionExtension('Core'));
    echo "ReflectionExtension serialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    unserialize('O:19:"ReflectionExtension":0:{}');
    echo "ReflectionExtension unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
Exception:Serialization of 'ReflectionClass' is not allowed
Exception:Unserialization of 'ReflectionClass' is not allowed
Exception:Serialization of 'ReflectionFunction' is not allowed
Exception:Unserialization of 'ReflectionFunction' is not allowed
Exception:Serialization of 'ReflectionMethod' is not allowed
Exception:Unserialization of 'ReflectionMethod' is not allowed
Exception:Serialization of 'ReflectionParameter' is not allowed
Exception:Unserialization of 'ReflectionParameter' is not allowed
Exception:Serialization of 'ReflectionAttribute' is not allowed
Exception:Unserialization of 'ReflectionAttribute' is not allowed
Exception:Serialization of 'ReflectionObject' is not allowed
Exception:Unserialization of 'ReflectionObject' is not allowed
Exception:Serialization of 'ReflectionProperty' is not allowed
Exception:Unserialization of 'ReflectionProperty' is not allowed
Exception:Serialization of 'ReflectionClassConstant' is not allowed
Exception:Unserialization of 'ReflectionClassConstant' is not allowed
Exception:Serialization of 'ReflectionExtension' is not allowed
Exception:Unserialization of 'ReflectionExtension' is not allowed
