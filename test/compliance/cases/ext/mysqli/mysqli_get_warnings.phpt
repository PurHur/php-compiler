--TEST--
ext/mysqli mysqli_get_warnings / mysqli_stmt_get_warnings + mysqli_warning::next (#22224)
--FILE--
<?php
declare(strict_types=1);

echo function_exists('mysqli_get_warnings') ? "get=yes\n" : "get=no\n";
echo function_exists('mysqli_stmt_get_warnings') ? "stmt_get=yes\n" : "stmt_get=no\n";
echo class_exists('mysqli_warning') ? "class=yes\n" : "class=no\n";
echo method_exists('mysqli', 'get_warnings') ? "mysqli_method=yes\n" : "mysqli_method=no\n";
echo method_exists('mysqli_stmt', 'get_warnings') ? "stmt_method=yes\n" : "stmt_method=no\n";
echo method_exists('mysqli_warning', 'next') ? "next=yes\n" : "next=no\n";

$rc = new ReflectionClass('mysqli_warning');
echo $rc->isFinal() ? "final=yes\n" : "final=no\n";
foreach (['message', 'sqlstate', 'errno'] as $p) {
    echo property_exists('mysqli_warning', $p) ? "prop_{$p}=yes\n" : "prop_{$p}=no\n";
}

try {
    new mysqli_warning();
    echo "construct=no\n";
} catch (Error $e) {
    echo "construct=yes\n";
}

try {
    mysqli_get_warnings();
    echo "arity_get=no\n";
} catch (ArgumentCountError $e) {
    echo "arity_get=yes\n";
}
try {
    mysqli_get_warnings(false);
    echo "type_get=no\n";
} catch (TypeError $e) {
    echo "type_get=yes\n";
}
try {
    mysqli_stmt_get_warnings();
    echo "arity_stmt=no\n";
} catch (ArgumentCountError $e) {
    echo "arity_stmt=yes\n";
}
?>
--EXPECT--
get=yes
stmt_get=yes
class=yes
mysqli_method=yes
stmt_method=yes
next=yes
final=yes
prop_message=yes
prop_sqlstate=yes
prop_errno=yes
construct=yes
arity_get=yes
type_get=yes
arity_stmt=yes
