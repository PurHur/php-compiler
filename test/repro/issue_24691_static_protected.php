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
