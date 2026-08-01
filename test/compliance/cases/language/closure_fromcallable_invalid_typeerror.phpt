--TEST--
language: Closure::fromCallable(non-callable) throws Zend TypeError (#26457, zend_closures.c)
--FILE--
<?php
function show($label, $v) {
    try {
        Closure::fromCallable($v);
        echo "$label: ok\n";
    } catch (TypeError $e) {
        echo "$label: TypeError:", $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo "$label: ", get_class($e), ":", $e->getMessage(), "\n";
    }
}

show('null', null);
show('int', 123);
show('float', 1.5);
show('bool', false);
show('obj', new stdClass);
show('arr12', [1, 2]);
show('empty', []);
show('one', [1]);
show('str1int', ['stdClass', 1]);
show('missing', [new stdClass, 'x']);

class Inv26457 {
    public function __invoke() {
        return 1;
    }
}
show('invoke', new Inv26457);
--EXPECT--
null: TypeError:Failed to create closure from callable: no array or string given
int: TypeError:Failed to create closure from callable: no array or string given
float: TypeError:Failed to create closure from callable: no array or string given
bool: TypeError:Failed to create closure from callable: no array or string given
obj: TypeError:Failed to create closure from callable: no array or string given
arr12: TypeError:Failed to create closure from callable: first array member is not a valid class name or object
empty: TypeError:Failed to create closure from callable: array callback must have exactly two members
one: TypeError:Failed to create closure from callable: array callback must have exactly two members
str1int: TypeError:Failed to create closure from callable: second array member is not a valid method
missing: TypeError:Failed to create closure from callable: class stdClass does not have a method "x"
invoke: ok
