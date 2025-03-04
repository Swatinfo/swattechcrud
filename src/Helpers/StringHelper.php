<?php

namespace SwatTech\Crud\Helpers;

use Illuminate\Support\Str;

/**
 * StringHelper
 *
 * This class provides static methods for string manipulation commonly used
 * throughout the CRUD generator package. It streamlines text conversions,
 * validation, and other string operations needed during code generation.
 *
 * @package SwatTech\Crud\Helpers
 */
class StringHelper
{
    /**
     * Convert a string to camelCase.
     *
     * Example: "user_profile" becomes "userProfile"
     *
     * @param string $value The string to convert
     * @return string The camelCase string
     */
    public static function camelCase(string $value): string
    {
        return Str::camel($value);
    }

    /**
     * Convert a string to StudlyCase (PascalCase).
     *
     * Example: "user_profile" becomes "UserProfile"
     *
     * @param string $value The string to convert
     * @return string The StudlyCase string
     */
    public static function studlyCase(string $value): string
    {
        return Str::studly($value);
    }

    /**
     * Convert a string to snake_case.
     *
     * Example: "UserProfile" becomes "user_profile"
     *
     * @param string $value The string to convert
     * @return string The snake_case string
     */
    public static function snakeCase(string $value): string
    {
        return Str::snake($value);
    }

    /**
     * Convert a singular word to its plural form.
     *
     * Example: "user" becomes "users"
     *
     * @param string $value The singular string
     * @return string The plural form of the string
     */
    public static function pluralize(string $value): string
    {
        return Str::plural($value);
    }

    /**
     * Convert a plural word to its singular form.
     *
     * Example: "users" becomes "user"
     *
     * @param string $value The plural string
     * @return string The singular form of the string
     */
    public static function singularize(string $value): string
    {
        return Str::singular($value);
    }

    /**
     * Generate a URL-friendly slug from a string.
     *
     * Example: "Hello World" becomes "hello-world"
     *
     * @param string $value The string to convert to a slug
     * @return string The generated slug
     */
    public static function generateSlug(string $value): string
    {
        return Str::slug($value);
    }

    /**
     * Parse a template string by replacing variables with their values.
     *
     * Example: "Hello, {{name}}!" with ['name' => 'John'] becomes "Hello, John!"
     *
     * @param string $template The template string with {{variable}} placeholders
     * @param array $variables An associative array of variable names and their values
     * @return string The parsed template with variables replaced
     */
    public static function parseTemplate(string $template, array $variables): string
    {
        // Replace {{variable}} style placeholders
        foreach ($variables as $key => $value) {
            $template = str_replace("{{" . $key . "}}", (string) $value, $template);
        }

        // Replace $VARIABLE style placeholders
        foreach ($variables as $key => $value) {
            $template = str_replace('$' . strtoupper($key), (string) $value, $template);
        }

        return $template;
    }

    /**
     * Check if a string is a valid PHP namespace.
     *
     * Valid namespaces must follow PSR-4 rules.
     *
     * @param string $namespace The namespace to validate
     * @return bool True if the namespace is valid, false otherwise
     */
    public static function isValidNamespace(string $namespace): bool
    {
        // Each part of the namespace must be a valid PHP label, 
        // and the namespace may contain multiple parts separated by backslashes
        $pattern = '/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*(\\\\[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)*$/';
        return preg_match($pattern, $namespace) === 1;
    }

    /**
     * Check if a string is a valid PHP class name.
     *
     * Valid class names must start with a letter or underscore,
     * followed by any number of letters, numbers, or underscores.
     *
     * @param string $className The class name to validate
     * @return bool True if the class name is valid, false otherwise
     */
    public static function isValidClassName(string $className): bool
    {
        $pattern = '/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/';
        return preg_match($pattern, $className) === 1;
    }

    /**
     * Generate a random string of the specified length.
     *
     * @param int $length The length of the random string
     * @return string The generated random string
     */
    public static function generateRandomString(int $length): string
    {
        return Str::random($length);
    }

    /**
     * Convert a table name to a model name.
     * 
     * Example: "blog_posts" becomes "BlogPost"
     *
     * @param string $tableName The database table name
     * @return string The corresponding model name
     */
    public static function tableToModel(string $tableName): string
    {
        return self::studlyCase(self::singularize($tableName));
    }

    /**
     * Convert a model name to a table name.
     * 
     * Example: "BlogPost" becomes "blog_posts"
     *
     * @param string $modelName The model class name
     * @return string The corresponding database table name
     */
    public static function modelToTable(string $modelName): string
    {
        return self::pluralize(self::snakeCase($modelName));
    }

    /**
     * Convert a class name to a readable title with spaces.
     * 
     * Example: "BlogPost" becomes "Blog Post"
     *
     * @param string $className The class name to convert
     * @return string Human-readable title
     */
    public static function classToTitle(string $className): string
    {
        return Str::title(Str::snake($className, ' '));
    }

    /**
     * Normalize line endings to use LF (\n) consistently.
     *
     * @param string $content Content with potentially mixed line endings
     * @return string Content with normalized line endings
     */
    public static function normalizeLineEndings(string $content): string
    {
        // Convert all line endings to LF
        return str_replace(["\r\n", "\r"], "\n", $content);
    }
}