<?php
declare(strict_types=1);

namespace AstrX\Routing;

use AstrX\Config\Config;

/**
 * Builds internal page URLs respecting the configured routing mode.
 *
 * Two methods:
 *
 *   toPage($resolvedUrlId, $params)
 *     — a page URL with its parameters.
 *     — Rewrite mode: parameters become name/value path segments,
 *       /en/admin-users/edit/5 (PathParameters says which values can).
 *     — Query mode: ?key=val, as always.
 *     — Used by: NavbarHandler, form actions, anywhere linking to a page root.
 *
 *   toPagePath($resolvedUrlId, $segments, $queryParams)
 *     — a place below a page whose controller reads positions (a board's
 *       thread, a username); its parameters stay in the query string.
 *
 *   toSubPage($resolvedUrlId, $page, $order, $perPage, $defaultPage,
 *             $defaultOrder, $defaultPerPage)
 *     — page URL with pagination/filter sub-params.
 *     — Rewrite mode: sub-params become path segments.
 *       /en/main/3/asc/10
 *     — Query mode: sub-params become named query params.
 *       index.php?lang=en&page=main&pn=3&order=asc&show=10
 *     — Trailing segments equal to their defaults are omitted in rewrite mode
 *       so /en/main/1/desc/20 becomes /en/main when all are default.
 *
 * Sub-param key names (query mode):
 *   pn    — page number  (avoids conflict with routing 'page' key)
 *   order — 'asc' or 'desc'
 *   show  — items per page
 *
 * Cookieless sessions: when `Session.use_cookies` is false, the session id must
 * travel in every generated URL or navigation loses the session (Tor operators
 * may disable browser cookies entirely). The sid is injected as a path segment
 * in rewrite mode ([locale]/<sid>/page/…, matching the router's expected slot)
 * and as the `Routing.session_key` query param in query mode. In cookie mode the
 * sid is empty here and URLs are unchanged. Only INTERNAL page URLs pass through
 * this class, so an external link never receives a sid.
 *
 * $resolvedUrlId must already be translated for the current locale.
 */
final class UrlGenerator
{
    public function __construct(
        private readonly Config     $config,
        private readonly CurrentUrl $currentUrl,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Build the URL of a page with its parameters.
     *
     * In rewrite mode each parameter that can be a path segment is written as
     * /name/value after the page (PathParameters::split()); the others, and
     * every parameter in query mode, go in the query string. The router reads
     * both back the same way, so a controller does not care which form a URL
     * had. Do not use this for a page whose controller reads positional path
     * segments (AstrX\Kernel\ReadsPathSegments): use toPagePath().
     *
     * @param array<string, scalar> $queryParams
     */
    public function toPage(string $resolvedUrlId, array $queryParams = [], bool $includeSid = true): string
    {
        [$urlRewrite, $basePath, $localeKey, $pageKey, $entryPoint, $locale, $sessionKey, $sid] =
            $this->routingConfig();

        // Callers that build a URL destined to leave the origin (e.g. a link
        // emailed to a clearnet inbox) pass includeSid:false so the cookieless
        // session id is never serialized into it (a hijack vector — see
        // EmailService::buildTokenLink and the sitemap/feed sid-free policy).
        if (!$includeSid) { $sid = ''; }

        if ($urlRewrite) {
            [$pairs, $rest] = PathParameters::split($queryParams, $this->reservedKeys());
            $extra = $rest !== [] ? '?' . http_build_query($rest) : '';
            $base = rtrim($basePath, '/');
            // Segment order MUST match the router: [locale] / [sid] / page / tail
            $segs = [];
            if ($locale !== '') { $segs[] = $locale; }
            if ($sid    !== '') { $segs[] = $sid; }
            $segs[] = $resolvedUrlId;
            foreach ($pairs as $pair) { $segs[] = rawurlencode($pair); }
            return $this->withRoutePrefix($base . '/' . implode('/', $segs) . $extra);
        }

        $query = [];
        if ($locale !== '') {
            $query[$localeKey] = $locale;
        }
        $query[$pageKey] = $resolvedUrlId;
        $query           = array_merge($query, $queryParams);
        if ($sid !== '') {
            // After the merge so a caller's $queryParams can't drop/override it.
            $query[$sessionKey] = $sid;
        }

        return $this->withRoutePrefix($entryPoint . '?' . http_build_query($query));
    }

    /**
     * Build the URL of a place below a page: a board, a thread, a wiki page.
     *
     *   rewrite: /en/board/b/thread/4?quote=5
     *   query:   index.php?lang=en&page=board%2Fb%2Fthread%2F4&quote=5
     *
     * The router hands the segments to the page's controller as its tail
     * (CurrentUrl::tailSegment()) in both modes. Appending "/b" to toPage()
     * instead only works with rewriting: in query mode the segment lands on
     * whatever parameter came last.
     *
     * A controller that also reads name/value pairs after its own positions
     * (it knows where they start) passes $pathParams: the parameters that can
     * be segments then follow the positions, /en/board/b/thread/4/quote/12.
     *
     * @param list<string>          $segments    raw segment values (encoded here)
     * @param array<string, scalar> $queryParams
     */
    public function toPagePath(string $resolvedUrlId, array $segments, array $queryParams = [], bool $includeSid = true, bool $pathParams = false): string
    {
        $segments = array_values(array_filter($segments, static fn (string $s): bool => $s !== ''));
        [$urlRewrite] = $this->routingConfig();
        if (!$urlRewrite) {
            $page = $segments === [] ? $resolvedUrlId : $resolvedUrlId . '/' . implode('/', $segments);
            return $this->toPage($page, $queryParams, $includeSid);
        }
        // The page reads positions, so its parameters stay in the query string
        // even with no segment: /en/board?style=x, not /en/board/style/x.
        $page = $this->toPage($resolvedUrlId, [], $includeSid);
        if ($pathParams) {
            [$pairs, $queryParams] = PathParameters::split($queryParams, $this->reservedKeys());
            $segments = [...$segments, ...$pairs];
        }
        $path = $segments !== [] ? '/' . implode('/', array_map('rawurlencode', $segments)) : '';
        $extra = $queryParams !== [] ? '?' . http_build_query($queryParams) : '';
        return $page . $path . $extra;
    }

    /**
     * Add parameters to a URL made by toPage(), the way toPage() would have
     * written them: path pairs in rewrite mode (before any query string the
     * URL already has), the query string otherwise.
     *
     * @param array<string, scalar> $params
     */
    public function withParams(string $pageUrl, array $params): string
    {
        if ($params === []) {
            return $pageUrl;
        }
        [$urlRewrite] = $this->routingConfig();
        [$path, $query] = array_pad(explode('?', $pageUrl, 2), 2, '');
        $rest = $params;
        if ($urlRewrite) {
            [$pairs, $rest] = PathParameters::split($params, $this->reservedKeys());
            if ($pairs !== []) {
                $path = rtrim($path, '/') . '/' . implode('/', array_map('rawurlencode', $pairs));
            }
        }
        if ($rest !== []) {
            $query = ($query !== '' ? $query . '&' : '') . http_build_query($rest);
        }
        return $query !== '' ? $path . '?' . $query : $path;
    }

    /**
     * A URL made by toPage() followed by the start of one more parameter, for
     * a template that appends the value itself ({{edit_url_prefix}}{{id}}):
     * ".../edit/" in rewrite mode, "...&edit=" otherwise. The value must be
     * one PathParameters accepts as a segment (an id, a slug) and be URL-encoded.
     */
    public function paramPrefix(string $pageUrl, string $name): string
    {
        [$urlRewrite] = $this->routingConfig();
        if ($urlRewrite && !str_contains($pageUrl, '?') && PathParameters::isPathName($name, $this->reservedKeys())) {
            return rtrim($pageUrl, '/') . '/' . $name . '/';
        }
        return $pageUrl . (str_contains($pageUrl, '?') ? '&' : '?') . rawurlencode($name) . '=';
    }

    /** @return list<string> the router's own query names, which never become segments */
    private function reservedKeys(): array
    {
        [, , $localeKey, $pageKey, , , $sessionKey] = $this->routingConfig();
        return [$localeKey, $sessionKey, $pageKey];
    }

    /**
     * Build a page URL with pagination/filter sub-params.
     *
     * In rewrite mode, sub-params become positional path segments:
     *   /en/main/3/asc/10
     * Trailing segments that equal their respective defaults are stripped:
     *   page=1, order='desc', perPage=default → /en/main (bare)
     *   page=3, order='desc', perPage=default → /en/main/3
     *   page=1, order='asc',  perPage=10      → /en/main/1/asc/10
     *
     * In query mode, sub-params become named query parameters:
     *   index.php?lang=en&page=main&pn=3&order=asc&show=10
     * Default values are also omitted in query mode for clean URLs.
     *
     * @param array<string, scalar> $extraQuery Additional query-string params.
     * @param list<string> $pathSegments Path segments appended after page root.
     */
    public function toSubPage(
        string $resolvedUrlId,
        int    $page,
        string $order,
        int    $perPage,
        int    $defaultPage    = 1,
        string $defaultOrder   = 'desc',
        int    $defaultPerPage = 20,
        array  $extraQuery     = [],
        array  $pathSegments   = [],  // rewrite-mode extra segments appended after primary
    ): string {
        [$urlRewrite, $basePath, $localeKey, $pageKey, $entryPoint, $locale, $sessionKey, $sid] =
            $this->routingConfig();

        if ($urlRewrite) {
            return $this->rewriteSubPage(
                $basePath, $locale, $sid, $resolvedUrlId,
                $page, $order, $perPage,
                $defaultPage, $defaultOrder, $defaultPerPage,
                $extraQuery, $pathSegments,
            );
        }

        return $this->querySubPage(
            $entryPoint, $localeKey, $locale, $pageKey, $sessionKey, $sid, $resolvedUrlId,
            $page, $order, $perPage,
            $defaultPage, $defaultOrder, $defaultPerPage,
            $extraQuery,
        );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /** @return array{bool, string, string, string, string, string, string, string} */
    private function routingConfig(): array
    {
        $urlRewrite  = $this->config->getConfigBool('Routing', 'url_rewrite',  true);
        $basePath    = $this->config->getConfigString('Routing', 'base_path',    '/');
        $localeKey   = $this->config->getConfigString('Routing', 'locale_key',   'lang');
        $pageKey     = $this->config->getConfigString('Routing', 'page_key',     'page');
        $entryPoint  = $this->config->getConfigString('Routing', 'entry_point',  'index.php');
        $localeRaw   = $this->currentUrl->get($localeKey, '');
        $locale      = is_string($localeRaw) ? $localeRaw : '';

        // Cookieless session propagation (see class docblock). Read the live sid
        // from CurrentUrl (ContentManager stamps it there post-regeneration), but
        // ONLY when the deployment is cookieless — otherwise $sid stays '' and no
        // URL is altered.
        $sessionKey  = $this->config->getConfigString('Routing', 'session_key', 'sid');
        $useCookies  = $this->config->getConfigBool('Session', 'use_cookies', true);
        $sid         = '';
        if (!$useCookies) {
            // Read the LIVE session id, NOT a CurrentUrl snapshot: the login path
            // calls session_regenerate_id(true) mid-request (UserService::login),
            // so an id stamped into CurrentUrl at routing time goes stale and the
            // post-login redirect would carry a destroyed sid — landing the user
            // on a dead session (logged out). session_id() always reflects the
            // current, post-regeneration id. (Matches DefaultTemplateContext.)
            $sidVal = session_id();
            $sid    = is_string($sidVal) && $sidVal !== '' ? $sidVal : '';
        }

        return [$urlRewrite, $basePath, $localeKey, $pageKey, $entryPoint, $locale, $sessionKey, $sid];
    }

    /**
     * @param array<string, scalar> $extraQuery
     * @param list<string>          $pathSegments  Extra segments appended after primary.
     *                                             When non-empty all three primary segments
     *                                             are always emitted so positions are unambiguous.
     */
    private function rewriteSubPage(
        string $basePath,
        string $locale,
        string $sid,
        string $resolvedUrlId,
        int    $page,
        string $order,
        int    $perPage,
        int    $defaultPage,
        string $defaultOrder,
        int    $defaultPerPage,
        array  $extraQuery,
        array  $pathSegments = [],
    ): string {
        $base = rtrim($basePath, '/');
        // Root = [locale] / [sid] / page  (sid slot matches the router, §parse).
        $rootSegs = [];
        if ($locale !== '') { $rootSegs[] = $locale; }
        if ($sid    !== '') { $rootSegs[] = $sid; }
        $rootSegs[] = $resolvedUrlId;
        $root = $base . '/' . implode('/', $rootSegs);

        if ($pathSegments !== []) {
            // When secondary segments are present, all three primary segments must
            // be emitted so their positions are fixed and unambiguous to the router.
            $primary = [(string) $page, $order, (string) $perPage];
        } else {
            // Standard right-to-left trailing-default stripping.
            $primary = [];
            if ($perPage !== $defaultPerPage) {
                array_unshift($primary, (string) $perPage);
            }
            if ($order !== $defaultOrder || $primary !== []) {
                array_unshift($primary, $order);
            }
            if ($page !== $defaultPage || $primary !== []) {
                array_unshift($primary, (string) $page);
            }
        }

        $allSegments = array_merge($primary, $pathSegments);
        $path = $allSegments !== []
            ? $root . '/' . implode('/', $allSegments)
            : $root;

        $extra = $extraQuery !== [] ? '?' . http_build_query($extraQuery) : '';
        return $this->withRoutePrefix($path . $extra);
    }

    /** @param array<string, scalar> $extraQuery */
    private function querySubPage(
        string $entryPoint,
        string $localeKey,
        string $locale,
        string $pageKey,
        string $sessionKey,
        string $sid,
        string $resolvedUrlId,
        int    $page,
        string $order,
        int    $perPage,
        int    $defaultPage,
        string $defaultOrder,
        int    $defaultPerPage,
        array  $extraQuery,
    ): string {
        $query = [];
        if ($locale !== '') {
            $query[$localeKey] = $locale;
        }
        $query[$pageKey] = $resolvedUrlId;

        if ($page !== $defaultPage) {
            $query['pn'] = $page;
        }
        if ($order !== $defaultOrder) {
            $query['order'] = $order;
        }
        if ($perPage !== $defaultPerPage) {
            $query['show'] = $perPage;
        }

        $query = array_merge($query, $extraQuery);
        if ($sid !== '') {
            $query[$sessionKey] = $sid;
        }

        return $this->withRoutePrefix($entryPoint . '?' . http_build_query($query));
    }

    private function withRoutePrefix(string $url): string
    {
        if (!defined('ASTRX_COMPILED_ROUTE_PREFIX')) {
            return $url;
        }

        $prefixValue = constant('ASTRX_COMPILED_ROUTE_PREFIX');
        $prefix = is_scalar($prefixValue) ? (string) $prefixValue : '';
        $prefix = '/' . trim($prefix, '/');
        if ($prefix === '/') {
            return $url;
        }

        if ($url === '' || str_starts_with($url, '#')) {
            return $url;
        }

        $lower = strtolower($url);
        if (str_starts_with($lower, 'http://')
            || str_starts_with($lower, 'https://')
            || str_starts_with($lower, 'mailto:')
            || str_starts_with($lower, 'tel:')
            || str_starts_with($lower, 'data:')
            || str_starts_with($lower, 'javascript:')) {
            return $url;
        }

        if (str_starts_with($url, $prefix . '/') || $url === $prefix) {
            return $url;
        }

        if ($url[0] === '/') {
            return $prefix . ($url === '/' ? '' : $url);
        }

        // Query-mode entry points are often configured as "index.php".
        // In compiled benchmark mode they must remain inside /compile.
        if (preg_match('#^[A-Za-z0-9_.-]+\.php(?:\?|$)#', $url) === 1) {
            return $prefix . '/' . $url;
        }

        return $url;
    }
}
