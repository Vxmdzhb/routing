<?php
declare(strict_types=1);

namespace AstrX\Routing;

/**
 * Page parameters as path segments instead of a query string.
 *
 * In rewrite mode a page's parameters travel as name/value pairs after the
 * page, the same parameters in the same order:
 *
 *   /en/admin-users/edit/5          instead of  /en/admin-users?edit=5
 *   /en/webmail/folder/Sent/uid/19  instead of  /en/webmail?folder=Sent&uid=19
 *
 * The router reads the pairs back into the request's query bag, so a
 * controller reads `edit` the same way whichever form the URL had.
 *
 * Some values cannot be a path segment and stay in the query string:
 *   - an empty value, an array, a value containing "/" (it would split);
 *   - a value starting with "." or containing ".php": web servers route those
 *     paths themselves (hidden files are denied, "*.php" goes to PHP-FPM
 *     directly), so the request would never reach the application;
 *   - a name that is not a plain identifier, and the router's own names
 *     (locale, session, page, api) and the form-submission tokens, which
 *     are read before the page is known.
 *
 * Pages whose controllers read the path themselves (a board and thread, a
 * username, a content slug) keep their parameters in the query string: their
 * segments are positions, not pairs. See AstrX\Kernel\ReadsPathSegments.
 */
final class PathParameters
{
    /** Names that always stay in the query string, whatever their value. */
    public const array QUERY_ONLY = ['api', '_prg', '_cp'];

    private const string NAME_PATTERN = '/\A[A-Za-z_][A-Za-z0-9_]*\z/';

    /**
     * Split parameters into path pairs and what must stay in the query string.
     *
     * @param array<array-key, mixed> $params
     * @param list<string>            $reserved names that stay in the query
     *                                          (the router's locale, session and page keys)
     * @return array{0: list<string>, 1: array<array-key, mixed>}
     *         [raw segments name, value, name, value…; the query-string rest]
     */
    public static function split(array $params, array $reserved = []): array
    {
        $segments = [];
        $rest     = [];
        foreach ($params as $name => $value) {
            $segment = is_string($name) && self::isPathName($name, $reserved) ? self::pathValue($value) : null;
            if ($segment === null) {
                $rest[$name] = $value;
                continue;
            }
            $segments[] = $name;
            $segments[] = $segment;
        }
        return [$segments, $rest];
    }

    /**
     * The name/value pairs in a page's remaining path segments. A later pair
     * wins over an earlier one with the same name; an odd last segment, a
     * reserved name or one that is not an identifier is skipped.
     *
     * @param list<string> $tail     decoded segments after the page
     * @param list<string> $reserved
     * @return array<string, string>
     */
    public static function parse(array $tail, array $reserved = []): array
    {
        $pairs = [];
        $count = count($tail);
        for ($i = 0; $i + 1 < $count; $i += 2) {
            $name = $tail[$i];
            if (self::isPathName($name, $reserved)) {
                $pairs[$name] = $tail[$i + 1];
            }
        }
        return $pairs;
    }

    /** @param list<string> $reserved */
    public static function isPathName(string $name, array $reserved = []): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1
            && !in_array($name, self::QUERY_ONLY, true)
            && !in_array($name, $reserved, true);
    }

    /** The value as a path segment (not yet encoded), or null when it must stay in the query. */
    public static function pathValue(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (!is_string($value) || $value === '' || $value === '.' || $value === '..') {
            return null;
        }
        if (str_contains($value, '/') || str_starts_with($value, '.') || stripos($value, '.php') !== false
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }
        return $value;
    }
}
