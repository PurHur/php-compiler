--TEST--
static:: to protected method from parent class resolves to child (#24691)
--FILE--
<?php
abstract class Model {
    abstract protected static function table(): string;

    public static function find(int $id): string {
        return "SELECT * FROM " . static::table() . " WHERE id = $id";
    }
}

class Post extends Model {
    protected static function table(): string { return 'posts'; }
}

class Comment extends Model {
    protected static function table(): string { return 'comments'; }
}

echo Post::find(1) . "\n";
echo Comment::find(2) . "\n";
--EXPECT--
SELECT * FROM posts WHERE id = 1
SELECT * FROM comments WHERE id = 2
