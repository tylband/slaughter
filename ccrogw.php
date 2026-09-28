<?php
declare(strict_types=1);

/**
 * CCRO MIS web application gateway (MIS / CentOS server).
 *
 * Upload to CentOS: /var/www/html/api/lcr/ccrogw.php
 * Public: https://sakatamalaybalay.com/api/lcr/ccrogw.php
 * Target: https://192.168.108.89:4433/elcr/eMenu/
 *
 * Apps on this host: https://192.168.108.89:4433/elcr/
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/ccrogw_error.log');

if (PHP_VERSION_ID < 70400) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Gateway requires PHP 7.4 or later. This server is running PHP ' . PHP_VERSION;
    exit;
}

set_exception_handler(static function ($e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'Gateway error: ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine();
});

register_shutdown_function(static function () {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array($err['type'], $fatal, true)) {
        return;
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'Gateway fatal: ' . $err['message'] . "\n" . $err['file'] . ':' . $err['line'];
});
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle)
    {
        $haystack = (string) $haystack;
        $needle = (string) $needle;
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle)
    {
        $haystack = (string) $haystack;
        $needle = (string) $needle;
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle)
    {
        $haystack = (string) $haystack;
        $needle = (string) $needle;
        if ($needle === '') {
            return true;
        }
        $len = strlen($needle);
        return strlen($haystack) >= $len && substr($haystack, -$len) === $needle;
    }
}

final class CcroOpaque
{
    public static function encode(string $key, string $path, string $query = ''): string
    {
        $payload = $path . "\n" . $query;
        $mac = substr(hash_hmac('sha256', $payload, $key, true), 0, 10);
        $bin = $mac . gzcompress($payload, 6);
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /**
     * @return array{path: string, query: string}|null
     */
    public static function decode(string $key, string $token): ?array
    {
        $b64 = strtr($token, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $bin = base64_decode($b64, true);
        if (!is_string($bin) || strlen($bin) < 12) {
            return null;
        }
        $mac = substr($bin, 0, 10);
        $raw = @gzuncompress(substr($bin, 10));
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $expect = substr(hash_hmac('sha256', $raw, $key, true), 0, 10);
        if (!hash_equals($mac, $expect)) {
            return null;
        }
        $parts = explode("\n", $raw, 2);
        $path = $parts[0] ?? '';
        $query = $parts[1] ?? '';
        if ($path === '' || $path[0] !== '/') {
            return null;
        }

        return ['path' => $path, 'query' => $query];
    }
}

final class CcroHttpClient
{
    /** @var array<string, mixed> */
    private array $config;

    /** @param array<string, mixed> $config */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, header_list: list<array{0: string, 1: string}>, body: string, error: ?string}
     */
    public function request(string $method, string $url, array $headers, string $body, bool $isMultipart): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return $this->fail('Unable to start HTTP client.');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $headerList = [];
        $responseHeaders = [];

        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => (int) ($this->config['connect_timeout'] ?? 8),
            CURLOPT_TIMEOUT => (int) ($this->config['timeout'] ?? 60),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headerList, &$responseHeaders): int {
                $trim = trim($line);
                if ($trim !== '' && !str_starts_with(strtolower($trim), 'http/')) {
                    $pos = strpos($trim, ':');
                    if ($pos !== false) {
                        $name = trim(substr($trim, 0, $pos));
                        $value = trim(substr($trim, $pos + 1));
                        $headerList[] = [$name, $value];
                        $key = strtolower($name);
                        if (isset($responseHeaders[$key])) {
                            $responseHeaders[$key] .= ', ' . $value;
                        } else {
                            $responseHeaders[$key] = $value;
                        }
                    }
                }
                return strlen($line);
            },
        ];

        if ($method !== 'GET' && $method !== 'HEAD') {
            if ($isMultipart) {
                $opts[CURLOPT_POSTFIELDS] = $this->buildMultipartFields();
            } elseif ($body !== '') {
                $opts[CURLOPT_POSTFIELDS] = $body;
            } elseif ($method === 'POST') {
                $opts[CURLOPT_POSTFIELDS] = '';
            }
        }

        if ($method === 'HEAD') {
            $opts[CURLOPT_NOBODY] = true;
        }

        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = $errno !== 0 ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return $this->fail($error ?: 'Backend request failed.');
        }

        $max = (int) ($this->config['max_body_bytes'] ?? 52428800);
        if (strlen($raw) > $max) {
            return $this->fail('Backend response is too large.');
        }

        return [
            'status' => $status > 0 ? $status : 502,
            'headers' => $responseHeaders,
            'header_list' => $headerList,
            'body' => $raw,
            'error' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMultipartFields(): array
    {
        $fields = $_POST;
        foreach ($_FILES as $name => $file) {
            if (!isset($file['tmp_name'])) {
                continue;
            }
            if (is_array($file['tmp_name'])) {
                continue;
            }
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $fields[$name] = new CURLFile(
                (string) $file['tmp_name'],
                (string) ($file['type'] ?? 'application/octet-stream'),
                (string) ($file['name'] ?? $name)
            );
        }

        return $fields;
    }

    /**
     * @return array{status: int, headers: array<string, string>, header_list: list<array{0: string, 1: string}>, body: string, error: string}
     */
    private function fail(string $message): array
    {
        return [
            'status' => 0,
            'headers' => [],
            'header_list' => [],
            'body' => '',
            'error' => $message,
        ];
    }
}

final class CcroRewriter
{
    private string $backendOrigin;
    private string $backendBasePath;
    private string $gatewayScriptUrl;
    private string $urlMode;
    private string $currentPath;
    private bool $opaque = false;
    private string $urlKey = '';
    private string $publicBaseUrl = '';

    /** @var list<string> */
    private array $backendNeedles = [];

    public function __construct(
        string $backendOrigin,
        string $backendBasePath,
        string $gatewayScriptUrl,
        string $urlMode,
        string $currentPath,
        bool $opaque = false,
        string $urlKey = '',
        string $publicBaseUrl = ''
    ) {
        $this->backendOrigin = rtrim($backendOrigin, '/');
        $this->backendBasePath = $this->normPath($backendBasePath === '' ? '/' : $backendBasePath);
        $this->gatewayScriptUrl = rtrim($gatewayScriptUrl, '/');
        $this->urlMode = $urlMode === 'query' ? 'query' : 'pathinfo';
        $this->currentPath = $this->normPath($currentPath === '' ? '/' : $currentPath);
        $this->opaque = $opaque && $urlKey !== '';
        $this->urlKey = $urlKey;
        $pub = rtrim($publicBaseUrl, '/');
        if ($pub === '') {
            $scriptPath = (string) (parse_url($this->gatewayScriptUrl, PHP_URL_PATH) ?: '/ccrogw.php');
            $dir = str_replace('\\', '/', dirname($scriptPath));
            $origin = (string) (parse_url($this->gatewayScriptUrl, PHP_URL_SCHEME) ?: 'http')
                . '://' . (string) (parse_url($this->gatewayScriptUrl, PHP_URL_HOST) ?: 'localhost');
            $port = parse_url($this->gatewayScriptUrl, PHP_URL_PORT);
            if ($port) {
                $origin .= ':' . $port;
            }
            $pub = $origin . (($dir === '/' || $dir === '.') ? '' : $dir);
        }
        $this->publicBaseUrl = $pub;

        $host = (string) (parse_url($this->backendOrigin, PHP_URL_HOST) ?? '');
        $port = parse_url($this->backendOrigin, PHP_URL_PORT);
        $scheme = (string) (parse_url($this->backendOrigin, PHP_URL_SCHEME) ?? 'http');

        if ($host !== '') {
            $hostPort = $host . ($port ? ':' . $port : '');
            $suffix = $this->backendBasePath === '/' ? '' : $this->backendBasePath;
            $this->backendNeedles[] = $this->backendOrigin . $suffix;
            $this->backendNeedles[] = $this->backendOrigin;
            $this->backendNeedles[] = '//' . $hostPort . $suffix;
            $this->backendNeedles[] = '//' . $hostPort;
            if ($port === null) {
                $defaultPort = $scheme === 'https' ? 443 : 80;
                $this->backendNeedles[] = $scheme . '://' . $host . ':' . $defaultPort . $suffix;
                $this->backendNeedles[] = $scheme . '://' . $host . ':' . $defaultPort;
            }
        }
        usort($this->backendNeedles, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    }

    public function rewriteUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || $url[0] === '#') {
            return $url;
        }

        $lower = strtolower($url);
        foreach (['javascript:', 'data:', 'mailto:', 'tel:', 'blob:'] as $skip) {
            if (str_starts_with($lower, $skip)) {
                return $url;
            }
        }

        if (str_starts_with($url, '//')) {
            $abs = (parse_url($this->backendOrigin, PHP_URL_SCHEME) ?: 'http') . ':' . $url;
            return $this->rewriteAbsolute($abs) ?? $url;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1) {
            $rewritten = $this->rewriteAbsolute($url);
            return $rewritten ?? $url;
        }

        if (str_starts_with($url, '/')) {
            [$path, $query, $fragment] = $this->splitQueryFragment($url);
            $scriptPath = (string) (parse_url($this->gatewayScriptUrl, PHP_URL_PATH) ?: '');
            if ($scriptPath !== '' && str_starts_with(strtolower($path), strtolower($scriptPath))) {
                $rest = substr($path, strlen($scriptPath));
                $rest = ($rest === false || $rest === '') ? '/' : $this->normPath($rest);

                return $this->toGateway($rest, $query) . $fragment;
            }

            return $this->toGateway($this->stripBackendBase($path), $query) . $fragment;
        }

        // Relative links (eMenu uses ../eVital/index.php). Always absolutize through
        // the gateway so they never escape to /lcr/{app} without ccrogw.php.
        if (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
            [$path, $query, $fragment] = $this->splitQueryFragment($url);
            $resolved = $this->resolveRelative($this->currentPath, $path);

            return $this->toGateway($resolved, $query) . $fragment;
        }

        return $url;
    }

    public function rewriteLocation(string $location): string
    {
        return $this->rewriteUrl($location);
    }

    public function rewriteCookie(string $cookie): string
    {
        $cookie = trim($cookie);
        if ($cookie === '') {
            return '';
        }

        $gatewayHttps = str_starts_with(strtolower($this->gatewayScriptUrl), 'https://');
        $livePath = $this->gatewayCookiePath();

        // eVital expires dozens of historical Path= cookies on every request.
        // Collapsing those deletes onto the live gateway path wipes the session
        // cookie, so CSRF always fails (login_err=5) on HTTP reverse-proxy.
        if ($this->isCookieDeletion($cookie)) {
            return '';
        }

        $cookie = preg_replace_callback('/;\s*Domain\s*=\s*[^;]*/i', static fn () => '', $cookie) ?? $cookie;
        $cookie = preg_replace('/;\s*Path\s*=\s*[^;]*/i', '', $cookie) ?? $cookie;
        $cookie .= '; Path=' . $livePath;

        if (!$gatewayHttps) {
            $cookie = preg_replace('/;\s*Secure\b/i', '', $cookie) ?? $cookie;
            $cookie = preg_replace('/;\s*SameSite\s*=\s*None/i', '; SameSite=Lax', $cookie) ?? $cookie;
            if (!preg_match('/;\s*SameSite\s*=/i', $cookie)) {
                $cookie .= '; SameSite=Lax';
            }
        }

        return $cookie;
    }

    /**
     * Directory that contains ccrogw.php on the MIS host, with trailing slash.
     * e.g. /elcr/eMIS_Web_Connect/iConnect_Menus/ for this XAMPP gateway.
     */
    private function gatewayCookiePath(): string
    {
        $pub = $this->gatewayPublicBasePath();
        if ($pub === '') {
            return '/';
        }

        return $pub . '/';
    }

    /** Public folder path of ccrogw.php (no trailing slash). */
    private function gatewayPublicBasePath(): string
    {
        $scriptPath = (string) (parse_url($this->gatewayScriptUrl, PHP_URL_PATH) ?: '/ccrogw.php');
        $dir = str_replace('\\', '/', dirname($scriptPath));
        if ($dir === '/' || $dir === '.' || $dir === '') {
            return '';
        }

        return rtrim($dir, '/');
    }

    private function isCookieDeletion(string $cookie): bool
    {
        $eq = strpos($cookie, '=');
        if ($eq === false) {
            return true;
        }
        $rest = substr($cookie, $eq + 1);
        $semi = strpos($rest, ';');
        $value = trim($semi === false ? $rest : substr($rest, 0, $semi), " \t\"");
        if ($value === '' || strcasecmp($value, 'deleted') === 0) {
            return true;
        }
        if (preg_match('/;\s*Max-Age\s*=\s*(-?\d+)/i', $cookie, $m) === 1 && (int) $m[1] <= 0) {
            return true;
        }
        if (preg_match('/;\s*Expires\s*=\s*([^;]+)/i', $cookie, $m) === 1) {
            $t = strtotime(trim($m[1]));
            if ($t !== false && $t < time()) {
                return true;
            }
        }

        return false;
    }

    public function rewriteBody(string $body, string $contentType): string
    {
        $type = strtolower($contentType);
        $trim = ltrim($body);

        // PHP APIs often send JSON with Content-Type: text/html. Never inject the
        // HTML client shim into those — eVital registry then fails with
        // "Invalid server response: (function(){var pub=...".
        if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
            return $this->rewriteTextUrls($body);
        }

        if (str_contains($type, 'html')) {
            return $this->rewriteHtml($body);
        }
        if (str_contains($type, 'css')) {
            return $this->rewriteCss($body);
        }
        if (str_contains($type, 'javascript') || str_contains($type, 'ecmascript')) {
            // Leave Vite ./chunk.js and assets/foo.js alone. Rewriting them to
            // absolute URLs makes Vite do: /build/ + http://.../assets/foo.js
            return $this->rewriteTextUrls($body, false);
        }
        if (str_contains($type, 'json')) {
            return $this->rewriteTextUrls($body);
        }
        if (str_contains($type, 'svg') || str_contains($type, 'xml') || str_contains($type, 'text/plain')) {
            return $this->rewriteTextUrls($body);
        }

        return $body;
    }

    public function hideBackendLeak(string $text): string
    {
        return $this->rewriteTextUrls($text);
    }

    public function toGateway(string $path, string $query = ''): string
    {
        $fragment = '';
        $hashPos = strpos($path, '#');
        if ($hashPos !== false) {
            $fragment = substr($path, $hashPos);
            $path = substr($path, 0, $hashPos);
        }
        $qpos = strpos($path, '?');
        if ($qpos !== false) {
            $fromPath = substr($path, $qpos + 1);
            $path = substr($path, 0, $qpos);
            $query = $query === '' ? $fromPath : $fromPath . '&' . $query;
        }

        $path = $this->stripDuplicateBase($this->normPath($path));

        if ($this->shouldOpaqueEncode($path)) {
            $file = basename($path);
            if ($file === '' || $file === '/' || $file === '.') {
                $file = 'index.php';
            }
            // /x/TOKEN/index.php so Inertia relative "index.php?r=…" stays in this folder.
            $token = CcroOpaque::encode($this->urlKey, $path === '/' ? '/' : $path, '');
            $url = $this->gatewayScriptUrl . '/x/' . $token . '/' . $file;
            if ($query !== '') {
                $url .= '?' . ltrim($query, '?');
            }
            return $url . $fragment;
        }

        if ($this->urlMode === 'query') {
            $url = $this->gatewayScriptUrl . '?p=' . rawurlencode($path);
            if ($query !== '') {
                $url .= '&' . ltrim($query, '&');
            }
            return $url . $fragment;
        }

        // Path-info: always keep ccrogw.php in the URL when not in transparent mode.
        $base = (strcasecmp($this->publicBaseUrl, $this->gatewayScriptUrl) === 0)
            ? $this->gatewayScriptUrl
            : $this->publicBaseUrl;

        $url = $base . ($path === '/' ? '/' : $path);
        if ($query !== '') {
            $url .= '?' . ltrim($query, '?');
        }

        return $url . $fragment;
    }

    private function rewriteHtml(string $html): string
    {
        $attrNames = 'href|src|action|formaction|poster|cite|background|data-src|data-href|data-url|data-background|data-bs-target';
        $html = preg_replace_callback(
            '/\b(' . $attrNames . ')\s*=\s*(["\'])(.*?)\2/is',
            function (array $m): string {
                return $m[1] . '=' . $m[2] . $this->rewriteUrl($m[3]) . $m[2];
            },
            $html
        ) ?? $html;

        $html = preg_replace_callback(
            '/\bsrcset\s*=\s*(["\'])(.*?)\1/is',
            function (array $m): string {
                return 'srcset=' . $m[1] . $this->rewriteSrcset($m[2]) . $m[1];
            },
            $html
        ) ?? $html;

        $html = preg_replace_callback(
            '/<meta\b[^>]*http-equiv\s*=\s*["\']refresh["\'][^>]*>/i',
            function (array $m): string {
                return preg_replace_callback(
                    '/content\s*=\s*(["\'])(.*?)\1/i',
                    function (array $c): string {
                        $content = $c[2];
                        if (preg_match('/^(.*?)url\s*=\s*(.*)$/i', $content, $p) === 1) {
                            $target = html_entity_decode(trim($p[2], " \t\"'"), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                            $content = $p[1] . 'url=' . $this->rewriteUrl($target);
                        }
                        return 'content=' . $c[1] . $content . $c[1];
                    },
                    $m[0]
                ) ?? $m[0];
            },
            $html
        ) ?? $html;

        $html = preg_replace_callback(
            '/<style\b[^>]*>(.*?)<\/style>/is',
            fn (array $m): string => $this->rewriteCss($m[0]),
            $html
        ) ?? $html;

        $html = preg_replace_callback(
            '/<script\b([^>]*)>(.*?)<\/script>/is',
            function (array $m): string {
                return '<script' . $m[1] . '>' . $this->rewriteTextUrls($m[2]) . '</script>';
            },
            $html
        ) ?? $html;

        return $this->injectClientShim($this->rewriteTextUrls($html));
    }

    private function rewriteCss(string $css): string
    {
        $css = preg_replace_callback(
            '/url\(\s*(["\']?)(.*?)\1\s*\)/i',
            function (array $m): string {
                $q = $m[1];
                return 'url(' . $q . $this->rewriteUrl($m[2]) . $q . ')';
            },
            $css
        ) ?? $css;

        $css = preg_replace_callback(
            '/@import\s+(["\'])(.*?)\1/i',
            function (array $m): string {
                return '@import ' . $m[1] . $this->rewriteUrl($m[2]) . $m[1];
            },
            $css
        ) ?? $css;

        return $this->rewriteTextUrls($css);
    }

    private function rewriteSrcset(string $srcset): string
    {
        $parts = explode(',', $srcset);
        foreach ($parts as &$part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $bits = preg_split('/\s+/', $part, 2) ?: [$part];
            $bits[0] = $this->rewriteUrl($bits[0]);
            $part = implode(' ', $bits);
        }

        return implode(', ', $parts);
    }

    private function rewriteTextUrls(string $text, bool $rewriteRelatives = true): string
    {
        $public = $this->publicBaseUrl;
        $publicJson = str_replace('/', '\\/', $public);

        foreach ($this->backendNeedles as $needle) {
            if ($needle === '' || str_contains(strtolower($public), strtolower($needle))) {
                continue;
            }
            $text = str_ireplace($needle, $public, $text);
            $text = str_ireplace(str_replace('/', '\\/', $needle), $publicJson, $text);
        }

        $text = preg_replace('#https?:\\\\?/\\\\?/lcr(?=\\\\?/|/)#i', $public, $text) ?? $text;
        $text = preg_replace('#https?:\/\/lcr(?=/|$)#i', $public, $text) ?? $text;

        // Only strip /ccrogw.php → /lcr when this request is already transparent.
        $scriptPath = (string) (parse_url($this->gatewayScriptUrl, PHP_URL_PATH) ?: '/ccrogw.php');
        $publicPath = (string) (parse_url($this->publicBaseUrl, PHP_URL_PATH) ?: $this->backendBasePath);
        if ($scriptPath !== '' && $publicPath !== '' && strcasecmp($scriptPath, $publicPath) !== 0
            && !str_ends_with(strtolower($publicPath), strtolower(basename($scriptPath)))) {
            $text = preg_replace('#' . preg_quote($scriptPath, '#') . '(?=/|\?|#|$)#i', $publicPath, $text) ?? $text;
            $scriptJson = str_replace('/', '\\/', $scriptPath);
            $publicJsonPath = str_replace('/', '\\/', $publicPath);
            $text = preg_replace('#' . preg_quote($scriptJson, '#') . '(?=\\\\/|\?|#|$)#i', $publicJsonPath, $text) ?? $text;
        }

        // Absolute app path under the public folder without ccrogw.php in the path.
        $liveBase = $this->gatewayPublicBasePath();
        if (strcasecmp($this->publicBaseUrl, $this->gatewayScriptUrl) === 0
            && $liveBase !== '') {
            $baseQ = preg_quote($liveBase, '#');
            $nameQ = preg_quote(basename($scriptPath), '#');
            $text = preg_replace('#' . $baseQ . '/(?!' . $nameQ . ')#i', $scriptPath . '/', $text) ?? $text;
            $baseJson = str_replace('/', '\\/', $liveBase);
            $scriptJson = str_replace('/', '\\/', $scriptPath);
            $text = preg_replace('#' . preg_quote($baseJson, '#') . '\\\\/(?!' . $nameQ . ')#i', $scriptJson . '\\/', $text) ?? $text;
        }

        if ($this->opaque) {
            $text = $this->encodeGatewayPlaintext($text);
            if ($rewriteRelatives) {
                $text = $this->rewriteOpaqueRelatives($text);
            }
        }

        return $this->protectViteAssetConcat($text);
    }

    private function encodeGatewayPlaintext(string $text): string
    {
        $gw = preg_quote($this->gatewayScriptUrl, '#');
        $text = preg_replace_callback(
            '#' . $gw . '(/x/[A-Za-z0-9_-]+|/[^\s\'"\\\\<]+)#i',
            function (array $m): string {
                $rest = $m[1];
                if (str_starts_with($rest, '/x/')) {
                    return $this->gatewayScriptUrl . $rest;
                }
                $query = '';
                if (str_contains($rest, '?')) {
                    [$rest, $query] = explode('?', $rest, 2);
                }
                if ($rest === '' || $rest === '/') {
                    return $this->gatewayScriptUrl . $m[1];
                }
                return $this->toGateway($rest, $query);
            },
            $text
        ) ?? $text;

        $gwJson = preg_quote(str_replace('/', '\\/', $this->gatewayScriptUrl), '#');
        $text = preg_replace_callback(
            '#' . $gwJson . '(\\\\?/x\\\\?/[A-Za-z0-9_-]+|\\\\?/[^\s"\']+)#i',
            function (array $m): string {
                $rest = str_replace('\\/', '/', $m[1]);
                if (str_starts_with($rest, '/x/')) {
                    return str_replace('/', '\\/', $this->gatewayScriptUrl . $rest);
                }
                $query = '';
                if (str_contains($rest, '?')) {
                    [$rest, $query] = explode('?', $rest, 2);
                }
                if ($rest === '' || $rest === '/') {
                    return str_replace('/', '\\/', $this->gatewayScriptUrl . str_replace('\\/', '/', $m[1]));
                }
                return str_replace('/', '\\/', $this->toGateway($rest, $query));
            },
            $text
        ) ?? $text;

        $scriptPath = (string) (parse_url($this->gatewayScriptUrl, PHP_URL_PATH) ?: '/ccrogw.php');
        $sp = preg_quote($scriptPath, '#');
        $text = preg_replace_callback(
            '#([`\'"])' . $sp . '(/x/[A-Za-z0-9_-]+|/[^`\'"]+)\1#i',
            function (array $m): string {
                $rest = $m[2];
                if (str_starts_with($rest, '/x/')) {
                    return $m[0];
                }
                $query = '';
                if (str_contains($rest, '?')) {
                    [$rest, $query] = explode('?', $rest, 2);
                }
                $url = $this->toGateway($rest === '' ? '/' : $rest, $query);
                $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
                $q = parse_url($url, PHP_URL_QUERY);
                if ($q) {
                    $path .= '?' . $q;
                }

                return $m[1] . $path . $m[1];
            },
            $text
        ) ?? $text;

        $scriptJson = preg_quote(str_replace('/', '\\/', $scriptPath), '#');
        $text = preg_replace_callback(
            '#(["\'])' . $scriptJson . '(\\\\?/x\\\\?/[A-Za-z0-9_-]+|\\\\?/[^\s"\']+)\1#i',
            function (array $m): string {
                $rest = str_replace('\\/', '/', $m[2]);
                if (str_starts_with($rest, '/x/')) {
                    return $m[0];
                }
                $query = '';
                if (str_contains($rest, '?')) {
                    [$rest, $query] = explode('?', $rest, 2);
                }
                $url = $this->toGateway($rest === '' ? '/' : $rest, $query);
                $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
                $q = parse_url($url, PHP_URL_QUERY);
                if ($q) {
                    $path .= '?' . $q;
                }

                return $m[1] . str_replace('/', '\\/', $path) . $m[1];
            },
            $text
        ) ?? $text;

        return $text;
    }

    private function rewriteOpaqueRelatives(string $text): string
    {
        $public = null;
        if (preg_match('#^(.+?/public)(?:/|$)#', $this->currentPath, $m) === 1) {
            $public = $m[1];
        }

        return preg_replace_callback(
            '#([`\'"])((?:\./|\.\./|build/|assets/)[^`\'"]+)\1#',
            function (array $m) use ($public): string {
                $rel = $m[2];
                if ($public !== null && str_starts_with($rel, 'build/')) {
                    return $m[1] . $this->toGateway($public . '/' . $rel) . $m[1];
                }
                if ($public !== null && str_starts_with($rel, 'assets/')) {
                    return $m[1] . $this->toGateway($public . '/build/' . $rel) . $m[1];
                }
                return $m[1] . $this->rewriteUrl($rel) . $m[1];
            },
            $text
        ) ?? $text;
    }

    /**
     * Vite does: `/.../build/` + dep. If dep is already http:// or /..., that
     * becomes /build/http://... and 404s.
     */
    private function protectViteAssetConcat(string $text): string
    {
        return preg_replace(
            '#return\s*([`\'"])([^`\'"]*?/build/?)\1\s*\+\s*([A-Za-z_$][\w$]*)#',
            'return(/^(https?:|\\/)/.test($3)?$3:$1$2$1+$3)',
            $text
        ) ?? $text;
    }

    private function shouldOpaqueEncode(string $path): bool
    {
        if (!$this->opaque || $this->urlKey === '' || preg_match('#^/x/#', $path) === 1) {
            return false;
        }
        // Vite/Laravel files must keep a real folder path. import(`./Home-xxx.js`)
        // resolves against the JS URL; /ccrogw.php/x/TOKEN + ./Home-xxx.js is a 404.
        if (preg_match('#/(build|assets|storage|fonts|images|img|css|js|vendor)(/|$)#i', $path) === 1) {
            return false;
        }
        $ext = strtolower((string) pathinfo(rtrim($path, '/'), PATHINFO_EXTENSION));
        if ($ext === '') {
            return true;
        }

        return in_array($ext, ['php', 'html', 'htm'], true);
    }

    private function injectClientShim(string $html): string
    {
        if (stripos($html, 'data-ccro-gw-shim') !== false) {
            return $html;
        }
        // Only full HTML documents — never JSON, fragments, or API payloads.
        if (preg_match('/<(?:!DOCTYPE\s+html|html|head|body)\b/i', $html) !== 1) {
            return $html;
        }

        $pub = json_encode($this->publicBaseUrl);
        $pubPath = json_encode((string) (parse_url($this->publicBaseUrl, PHP_URL_PATH) ?: ''));
        $scriptPath = json_encode((string) (parse_url($this->gatewayScriptUrl, PHP_URL_PATH) ?: '/ccrogw.php'));
        $basePath = json_encode($this->gatewayPublicBasePath());
        $app = json_encode($this->toGateway($this->currentPath));
        $pathInfo = $this->publicBaseUrl === $this->gatewayScriptUrl ? '1' : '0';
        $shim = '<script data-ccro-gw-shim="1">'
            . '(function(){var pub=' . $pub . ',pubPath=' . $pubPath . ',script=' . $scriptPath
            . ',base=' . $basePath . ',app=' . $app . ',pathInfo=' . $pathInfo . ';'
            . 'function map(u){if(typeof u!=="string"||!u)return u;'
            . 'var d=u.search(/\\/build\\/https?:\\/\\//i);if(d!==-1)u=u.substring(d+7);'
            . 'if(u.charAt(0)==="#")return u;'
            . 'if(!pathInfo&&script&&u.indexOf(script)!==-1){u=u.split(script).join(pubPath||"");}'
            . 'u=u.replace(/^https?:\\/\\/+lcr(?=\\/|$)/i,pub);'
            . 'var q=u.indexOf("?")>=0?u.substring(u.indexOf("?")):"";'
            . 'if(u.charAt(0)==="?")return app.replace(/[?#].*$/,"")+u;'
            . 'if(/^(?:\\.\\/)?index\\.php(?:[?#]|$)/i.test(u)){var leaf=app.replace(/[?#].*$/,"");return /index\\.php$/i.test(leaf)?leaf+q:leaf.replace(/\\/[^/]*$/,"/")+"index.php"+q;}'
            . 'if(/\\/x\\/[^/?#]*\\./.test(u))return app.replace(/[?#].*$/,"")+q;'
            . 'if(pathInfo&&base&&u.charAt(0)==="/"&&(u===base||u.indexOf(base+"/")===0)&&u.indexOf(script)!==0){return script+u.substring(base.length);}'
            . 'if(pathInfo&&script&&base&&u.indexOf("://")!==-1){try{var abs=new URL(u,location.href);var ap=abs.pathname||"";if(ap.indexOf(base+"/")===0&&ap.indexOf(script)!==0)return script+ap.substring(base.length)+(abs.search||"");}catch(e1){}}'
            . 'if(pathInfo&&script&&u&&u.charAt(0)!=="/"&&u.charAt(0)!=="#"&&u.charAt(0)!=="?"&&!/^[a-z][a-z0-9+.-]*:/i.test(u)){try{var leaf=String(app||location.href).replace(/[?#].*$/,"");var pop=leaf.split("/").pop()||"";var baseUrl=/\\.[a-z0-9]+$/i.test(pop)?leaf:leaf.replace(/\\/?$/,"/");var r=new URL(u,baseUrl);var rp=r.pathname||"";if(rp.indexOf(script)===0)return rp+(r.search||"");if(base&&rp.indexOf(base+"/")===0)return script+rp.substring(base.length)+(r.search||"");if(base&&rp.indexOf(base)===0)return script+rp.substring(base.length)+(r.search||"");return script+rp+(r.search||"");}catch(e2){}}'
            . 'return u;}'
            . 'var f=window.fetch;if(f){window.fetch=function(i,n){if(typeof i==="string")i=map(i);else if(i&&i.url)i=new Request(map(i.url),i);return f.call(this,i,n);};}'
            . 'var o=XMLHttpRequest.prototype.open;XMLHttpRequest.prototype.open=function(m,u){if(typeof u==="string")arguments[1]=map(u);return o.apply(this,arguments);};'
            . 'document.addEventListener("click",function(e){var a=e.target&&e.target.closest&&e.target.closest("a");if(!a||e.defaultPrevented||e.button!==0||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey)return;var h=a.getAttribute("href");if(!h||h.charAt(0)==="#")return;var n=map(h);if(n&&n!==h){e.preventDefault();try{a.setAttribute("href",n);}catch(x){}location.assign(n);}},true);'
            . 'var ps=history.pushState,rs=history.replaceState;'
            . 'history.pushState=function(s,t,u){if(typeof u==="string")u=map(u);return ps.call(this,s,t,u);};'
            . 'history.replaceState=function(s,t,u){if(typeof u==="string")u=map(u);return rs.call(this,s,t,u);};'
            . '})();</script>';

        if (preg_match('/<head\\b[^>]*>/i', $html) === 1) {
            return preg_replace('/<head\\b[^>]*>/i', '$0' . $shim, $html, 1) ?? $html;
        }

        return $shim . $html;
    }

    /**
     * @return array{0: string, 1: string, 2: string} path, query, fragment (fragment includes #)
     */
    private function splitQueryFragment(string $url): array
    {
        $fragment = '';
        $hashPos = strpos($url, '#');
        if ($hashPos !== false) {
            $fragment = substr($url, $hashPos);
            $url = substr($url, 0, $hashPos);
        }
        $query = '';
        $qpos = strpos($url, '?');
        if ($qpos !== false) {
            $query = substr($url, $qpos + 1);
            $url = substr($url, 0, $qpos);
        }

        return [$url, $query, $fragment];
    }

    private function stripDuplicateBase(string $path): string
    {
        $scriptPath = (string) (parse_url($this->gatewayScriptUrl, PHP_URL_PATH) ?: '');
        if ($scriptPath !== '' && str_starts_with(strtolower($path), strtolower($scriptPath))) {
            $rest = substr($path, strlen($scriptPath));
            $path = $this->normPath($rest === false || $rest === '' ? '/' : $rest);
        }

        $base = $this->backendBasePath;
        if ($base === '/' || $base === '') {
            return $path;
        }
        while (str_starts_with(strtolower($path), strtolower($base) . '/')) {
            $path = $this->normPath(substr($path, strlen($base)));
        }

        return $path;
    }

    private function rewriteAbsolute(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        $backendHost = strtolower((string) parse_url($this->backendOrigin, PHP_URL_HOST));
        $gatewayHost = strtolower((string) parse_url($this->gatewayScriptUrl, PHP_URL_HOST));
        $path = $this->normPath($parts['path'] ?? '/');
        $query = $parts['query'] ?? '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        if ($host === $gatewayHost) {
            $scriptPath = (string) (parse_url($this->gatewayScriptUrl, PHP_URL_PATH) ?: '');
            if ($scriptPath !== '' && str_starts_with(strtolower($path), strtolower($scriptPath))) {
                $rest = substr($path, strlen($scriptPath));
                $rest = ($rest === false || $rest === '') ? '/' : $this->normPath($rest);
                if (preg_match('#^/x/#', $rest) === 1) {
                    return $this->gatewayScriptUrl . $rest . ($query !== '' ? '?' . $query : '') . $fragment;
                }
                return $this->toGateway($rest, $query) . $fragment;
            }
            return $this->toGateway($this->stripBackendBase($path), $query) . $fragment;
        }

        if ($host !== $backendHost) {
            return null;
        }

        return $this->toGateway($this->stripBackendBase($path), $query) . $fragment;
    }

    private function stripBackendBase(string $path): string
    {
        $path = $this->normPath($path);
        $base = $this->backendBasePath;
        if ($base !== '/' && str_starts_with(strtolower($path), strtolower($base))) {
            $rest = substr($path, strlen($base));
            return $this->normPath($rest === false || $rest === '' ? '/' : $rest);
        }

        return $path;
    }

    private function resolveRelative(string $currentPath, string $rel): string
    {
        $path = $this->normPath($currentPath === '' ? '/' : $currentPath);
        // /eMenu (no extension) is a directory; do not treat the last segment as a file
        // or ../app links resolve to /lcr/app and drop ccrogw.php.
        $baseName = basename($path);
        if ($path !== '/' && ($baseName === '' || !preg_match('#\.[a-z0-9]{1,8}$#i', $baseName))) {
            $dir = rtrim($path, '/') . '/';
        } else {
            $dir = preg_replace('#/[^/]*$#', '/', $path) ?? '/';
        }

        return $this->normPath($dir . $rel);
    }

    private function normPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $qpos = strpos($path, '?');
        if ($qpos !== false) {
            $path = substr($path, 0, $qpos);
        }
        $keepSlash = strlen($path) > 1 && substr($path, -1) === '/';
        $path = '/' . ltrim($path, '/');
        $parts = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }

        $out = '/' . implode('/', $parts);
        if ($keepSlash && $out !== '/') {
            $out .= '/';
        }

        return $out;
    }
}

final class CcroGateway
{
    private const HOP_HEADERS = [
        'connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization',
        'te', 'trailer', 'transfer-encoding', 'upgrade', 'host', 'content-length',
        'accept-encoding',
    ];

    private const SKIP_RESPONSE_HEADERS = [
        'connection', 'keep-alive', 'transfer-encoding', 'content-encoding',
        'content-length', 'server', 'x-powered-by', 'alt-svc',
        'content-security-policy', 'content-security-policy-report-only',
        'access-control-allow-origin', 'access-control-allow-credentials',
    ];

    /** @var array<string, mixed> */
    private array $config;

    private string $backendOrigin;
    private string $backendBasePath;
    private string $gatewayScriptUrl;
    private string $publicBaseUrl;
    private string $urlMode;
    private string $decodedQuery = '';

    /** @param array<string, mixed> $config */
    public function __construct(array $config)
    {
        $this->config = $config;

        $base = rtrim((string) ($config['backend_base_url'] ?? ''), '/');
        $parts = parse_url($base);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            $this->backendOrigin = '';
            $this->backendBasePath = '/';
        } else {
            $port = isset($parts['port']) ? ':' . $parts['port'] : '';
            $this->backendOrigin = $parts['scheme'] . '://' . $parts['host'] . $port;
            $basePath = $this->normPath($parts['path'] ?? '/');
            // If someone pastes .../lcr/eMenu instead of .../lcr, use the parent folder.
            if (preg_match('#/eMenu$#i', $basePath) === 1) {
                $parent = dirname($basePath);
                $basePath = ($parent === '\\' || $parent === '.') ? '/' : str_replace('\\', '/', $parent);
                $basePath = $this->normPath($basePath);
            }
            $this->backendBasePath = $basePath;
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
        $scheme = $https ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/ccrogw.php');
        $this->gatewayScriptUrl = $scheme . '://' . $host . $script;

        // Default: emit /lcr/ccrogw.php/{app}/... (works without Apache rewrite).
        // transparent_urls=true → /lcr/{app}/... (needs .htaccess) — only when that flag is on.
        $transparent = !empty($config['transparent_urls']);
        $this->publicBaseUrl = $transparent
            ? ($scheme . '://' . $host . ($this->backendBasePath === '/' ? '' : $this->backendBasePath))
            : $this->gatewayScriptUrl;

        $this->urlMode = ((string) ($config['url_mode'] ?? 'pathinfo')) === 'query' ? 'query' : 'pathinfo';
    }

    public function run(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('X-Frame-Options: SAMEORIGIN');

        if (!function_exists('curl_init')) {
            $this->errorPage(503, 'Gateway requires the PHP cURL extension (php-curl).');
            return;
        }

        if ($this->backendOrigin === '' || str_contains($this->backendOrigin, 'CHANGE_ME')) {
            $this->setupPage();
            return;
        }

        $path = $this->requestPath();
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        parse_str($query, $qs);
        unset($qs['p'], $qs['ccro_health']);
        if ($this->decodedQuery !== '') {
            parse_str($this->decodedQuery, $inner);
            $qs = array_merge($inner, $qs);
        }

        if ($path === '/' || $path === '') {
            $menu = $this->normPath((string) ($this->config['menu_path'] ?? '/eMenu/index.php'));
            $this->redirectToGateway($menu);
            return;
        }

        $mode = (string) ($this->config['menu_mode'] ?? 'proxy');
        $menuPath = $this->normPath((string) ($this->config['menu_path'] ?? '/eMenu/'));
        $isMenu = $this->isMenuPath($path, $menuPath);

        if ($mode === 'catalog' && $isMenu && $this->isMenuIndex($path, $menuPath)) {
            $this->renderCatalog();
            return;
        }

        $this->proxy($path, http_build_query($qs));
    }

    private function proxy(string $path, string $query, int $depth = 0): void
    {
        if ($depth > 4) {
            $this->errorPage(502, 'The application is temporarily unavailable.');
            return;
        }

        if ($depth === 0 && $this->looksLikeDirectory($path)) {
            $indexPath = rtrim($path, '/') . '/index.php';
            // Put index.php in the address bar so eMenu ../app links stay under ccrogw.php.
            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET') {
                $this->redirectToGateway($indexPath, $query);
                return;
            }
            $this->proxy($indexPath, $query, $depth + 1);
            return;
        }

        // Laravel: root index.php may work but Vite assets live under /public/.
        // Send the browser to /public/index.php so relative ./build/... resolves.
        if ($depth === 0 && $this->shouldPreferLaravelPublic($path)) {
            $publicPath = $this->laravelPublicIndex($path);
            if ($publicPath !== null && $this->backendPathExists($publicPath)) {
                $this->redirectToGateway($publicPath, $query);
                return;
            }
        }

        $target = $this->backendOrigin . $this->joinPath($this->backendBasePath, $path);
        if ($query !== '') {
            $target .= '?' . $query;
        }

        if (!$this->targetAllowed($target)) {
            $this->errorPage(502, 'Backend configuration error.');
            return;
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $isMultipart = $method === 'POST' && str_contains(
            strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')),
            'multipart/form-data'
        );
        $body = $isMultipart ? '' : (string) file_get_contents('php://input');
        if (!$isMultipart && $method === 'POST' && $body === '' && $_POST !== []) {
            $body = http_build_query($_POST);
        }

        $headers = $this->forwardHeaders($target, $path);
        if (!$this->headerHas($headers, 'Content-Type')) {
            $ct = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
            if ($ct !== '') {
                $headers['Content-Type'] = $ct;
            } elseif ($body !== '' && !$isMultipart && $method === 'POST') {
                $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            }
        }
        if (!$this->headerHas($headers, 'Cookie') && !empty($_SERVER['HTTP_COOKIE'])) {
            $headers['Cookie'] = (string) $_SERVER['HTTP_COOKIE'];
        }
        if ($isMultipart) {
            foreach (array_keys($headers) as $name) {
                if (strtolower((string) $name) === 'content-type') {
                    unset($headers[$name]);
                }
            }
        }
        $client = new CcroHttpClient($this->config);
        $result = $client->request($method, $target, $headers, $body, $isMultipart);

        if ($result['error'] !== null) {
            $menuPath = $this->normPath((string) ($this->config['menu_path'] ?? '/eMenu/'));
            if ($this->isMenuPath($path, $menuPath)) {
                $this->renderCatalog('The portal menu could not be loaded from the application server. Showing the catalog if available.');
                return;
            }
            $this->errorPage(502, 'The application is temporarily unavailable.');
            return;
        }

        $status = (int) $result['status'];
        $rewriter = $this->rewriter($path);

        if ($method === 'GET' && in_array($status, [301, 302, 303, 307, 308], true) && $this->looksLikeDirectory($path)) {
            $this->proxy(rtrim($path, '/') . '/index.php', $query, $depth + 1);
            return;
        }

        if ($method === 'GET' && $status === 404) {
            $publicIndex = $this->laravelPublicIndex($path);
            if ($publicIndex !== null) {
                $this->proxy($publicIndex, $query, $depth + 1);
                return;
            }
        }

        $contentType = $result['headers']['content-type'] ?? 'application/octet-stream';
        if ($status < 100) {
            $status = 502;
        }
        http_response_code($status);

        $body = $result['body'];
        $rewrite = $this->shouldRewrite($contentType);
        if ($rewrite) {
            $body = $rewriter->rewriteBody($body, $contentType);
        }

        $this->sendResponseHeaders($result['header_list'], $rewriter, $rewrite, strlen($body));
        echo $body;
    }

    private function looksLikeDirectory(string $path): bool
    {
        $base = basename(rtrim(str_replace('\\', '/', $path), '/'));
        if ($base === '') {
            return true;
        }
        if (str_ends_with($path, '/')) {
            return true;
        }

        return !str_contains($base, '.');
    }

    /**
     * Laravel apps often live at {app}/public/index.php. If {app}/index.php 404s, try that.
     */
    private function laravelPublicIndex(string $path): ?string
    {
        $norm = $this->normPath($path);
        if (preg_match('#/public(/index\.php)?$#i', $norm) === 1) {
            return null;
        }
        if (preg_match('#/index\.php$#i', $norm) === 1) {
            $dir = preg_replace('#/index\.php$#i', '', $norm) ?? $norm;
            return rtrim($dir, '/') . '/public/index.php';
        }
        if ($this->looksLikeDirectory($norm)) {
            return rtrim($norm, '/') . '/public/index.php';
        }

        return null;
    }

    private function shouldPreferLaravelPublic(string $path): bool
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            return false;
        }
        $norm = $this->normPath($path);

        return preg_match('#/index\.php$#i', $norm) === 1
            && preg_match('#/public/index\.php$#i', $norm) !== 1;
    }

    private function backendPathExists(string $path): bool
    {
        $url = $this->backendOrigin . $this->joinPath($this->backendBasePath, $path);
        if (!$this->targetAllowed($url)) {
            return false;
        }
        $client = new CcroHttpClient($this->config);
        $result = $client->request('HEAD', $url, ['Accept' => '*/*'], '', false);
        if ($result['error'] !== null) {
            return false;
        }
        $status = (int) $result['status'];
        if ($status === 405 || $status === 501) {
            $result = $client->request('GET', $url, ['Accept' => 'text/html'], '', false);
            if ($result['error'] !== null) {
                return false;
            }
            $status = (int) $result['status'];
        }

        return $status >= 200 && $status < 400;
    }

    private function renderCatalog(?string $notice = null): void
    {
        $apps = $this->fetchCatalogApps();
        $portal = is_array($this->config['portal'] ?? null) ? $this->config['portal'] : [];
        $tz = (string) ($portal['timezone'] ?? 'Asia/Manila');
        try {
            date_default_timezone_set($tz);
        } catch (\Throwable $e) {
            date_default_timezone_set('UTC');
        }
        $now = (new DateTimeImmutable('now'))->format('M d, Y h:i A');
        $rewriter = $this->rewriter('/eMenu/');

        $line2 = (string) ($portal['line2'] ?? 'Application Gateway');
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: private, max-age=0, must-revalidate');

        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . $h($line2) . ' — Portal</title>';
        echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
        echo '<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Outfit:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">';
        echo '<style>' . $this->catalogCss() . '</style></head><body>';
        echo '<div id="progress" aria-hidden="true"></div>';
        echo '<header class="portal-header"><div class="portal-header-bg"></div><div class="portal-header-grid"></div>';
        echo '<div class="portal-shell portal-shell--header"><div class="portal-top"><div class="brand">';
        echo '<div class="brand-seal" aria-hidden="true"><span class="brand-seal-inner">GW</span></div>';
        echo '<div class="brand-text">';
        $line1 = trim((string) ($portal['line1'] ?? ''));
        if ($line1 !== '') {
            echo '<div class="line1">' . $h($line1) . '</div>';
        }
        echo '<div class="line2">' . $h($line2) . '</div>';
        echo '<div class="line3">' . $h((string) ($portal['line3'] ?? 'Application Gateway')) . '</div>';
        echo '</div></div><div class="clock"><div class="clock-label">CURRENT TIME (' . $h($tz) . ')</div>';
        echo '<div class="clock-value">' . $h($now) . '</div></div></div>';
        echo '<div class="search-wrap"><div class="search-field">';
        echo '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>';
        echo '<label for="portal-search" class="visually-hidden">Search systems</label>';
        echo '<input type="search" id="portal-search" placeholder="Search systems by name..." autocomplete="off">';
        echo '</div></div></div></header><main class="portal-main"><div class="portal-shell">';
        if ($notice !== null && $notice !== '') {
            echo '<p class="notice">' . $h($notice) . '</p>';
        }
        echo '<div class="grid" id="app-grid">';

        if ($apps === []) {
            echo '<p class="empty-state" id="empty-state">No applications were returned by the portal catalog.</p>';
        } else {
            foreach ($apps as $app) {
                $title = (string) ($app['title'] ?? '');
                $sub = (string) ($app['subtitle'] ?? '');
                $appPath = (string) ($app['path'] ?? '/');
                $href = $rewriter->toGateway($appPath);
                $logoPath = (string) ($app['logo_path'] ?? '');
                $logoUrl = $logoPath !== '' ? $rewriter->toGateway($logoPath) : '';
                $compact = preg_replace('/\s+/', '', $title) ?: 'A';
                $abbr = trim((string) ($app['abbr'] ?? ''));
                if ($abbr === '') {
                    $abbr = strtoupper(function_exists('mb_substr') ? mb_substr($compact, 0, 2) : substr($compact, 0, 2));
                }
                echo '<a class="app-card" href="' . $h($href) . '" data-title="' . $h($title) . '" data-subtitle="' . $h($sub) . '">';
                echo '<span class="app-icon" aria-hidden="true">';
                if ($logoUrl !== '') {
                    echo '<img src="' . $h($logoUrl) . '" alt="" loading="lazy" data-abbr="' . $h($abbr) . '" onerror="window.__gwLogoFallback(this)">';
                } else {
                    echo $h($abbr);
                }
                echo '</span><h2 class="app-title">' . $h($title) . '</h2>';
                if ($sub !== '') {
                    echo '<p class="app-sub">' . $h($sub) . '</p>';
                }
                echo '</a>';
            }
            echo '<p class="empty-state" id="empty-state" hidden>No applications match your search.</p>';
        }

        echo '</div></div></main><script>' . $this->catalogJs() . '</script></body></html>';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchCatalogApps(): array
    {
        $jsonPath = $this->normPath((string) ($this->config['menu_json_path'] ?? '/eMenu/apps.json.php'));
        $url = $this->backendOrigin . $this->joinPath($this->backendBasePath, $jsonPath);
        if (!$this->targetAllowed($url)) {
            return [];
        }

        $headers = [
            'Accept' => 'application/json',
        ];
        $token = trim((string) ($this->config['gateway_token'] ?? ''));
        if ($token !== '') {
            $headers['X-Ccro-Gateway-Token'] = $token;
        }

        $client = new CcroHttpClient($this->config);
        $result = $client->request('GET', $url, $headers, '', false);
        if ($result['error'] !== null || $result['status'] >= 400) {
            return [];
        }

        $data = json_decode($result['body'], true);
        if (!is_array($data) || empty($data['ok']) || !is_array($data['apps'] ?? null)) {
            return [];
        }

        $apps = [];
        foreach ($data['apps'] as $row) {
            if (is_array($row)) {
                $apps[] = $row;
            }
        }

        return $apps;
    }

    /**
     * Diagnostic payload for ?ccro_health=1. Never includes the PC3 host.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function healthReport(array $payload)
    {
        $configured = $this->backendOrigin !== '' && strpos($this->backendOrigin, 'CHANGE_ME') === false;
        $payload['config'] = $configured ? 'ok' : 'needs_backend_base_url';
        $payload['opaque'] = $this->opaqueEnabled();
        $payload['url_mode'] = $this->urlMode;
        $payload['public'] = !empty($this->config['transparent_urls']) ? 'transparent' : 'pathinfo';
        $payload['portal'] = $this->rewriter('/')->toGateway(
            $this->normPath((string) ($this->config['menu_path'] ?? '/eMenu/index.php'))
        );
        $shortcuts = $this->config['shortcuts'] ?? [];
        if (is_array($shortcuts) && $shortcuts !== []) {
            $payload['shortcuts'] = array_keys($shortcuts);
        }

        if (!$configured) {
            $payload['backend'] = 'not_configured';
            $payload['status'] = 'SETUP';
            return $payload;
        }

        if (empty($payload['curl'])) {
            $payload['backend'] = 'unchecked';
            $payload['status'] = 'DOWN';
            $payload['error'] = 'PHP cURL extension is not installed. On CentOS: yum/dnf install php-curl && systemctl restart httpd';
            return $payload;
        }

        try {
            $jsonPath = $this->normPath((string) ($this->config['menu_json_path'] ?? '/eMenu/apps.json.php'));
            $menuPath = $this->normPath((string) ($this->config['menu_path'] ?? '/eMenu/'));
            $menuIndex = rtrim($menuPath, '/') . '/index.php';

            $headers = ['Accept' => 'application/json'];
            $token = trim((string) ($this->config['gateway_token'] ?? ''));
            if ($token !== '') {
                $headers['X-Ccro-Gateway-Token'] = $token;
            }

            $client = new CcroHttpClient($this->config);

            $jsonUrl = $this->backendOrigin . $this->joinPath($this->backendBasePath, $jsonPath);
            $menuUrl = $this->backendOrigin . $this->joinPath($this->backendBasePath, $menuIndex);

            $jsonOk = false;
            $menuOk = false;
            $jsonStatus = 0;
            $menuStatus = 0;

            if ($this->targetAllowed($jsonUrl)) {
                $result = $client->request('GET', $jsonUrl, $headers, '', false);
                $jsonStatus = (int) $result['status'];
                $jsonOk = $result['error'] === null && $jsonStatus >= 200 && $jsonStatus < 400;
                if ($result['error'] !== null) {
                    $payload['backend_error'] = $this->stripSecrets((string) $result['error']);
                }
                if ($jsonOk) {
                    $data = json_decode($result['body'], true);
                    if (is_array($data) && isset($data['count'])) {
                        $payload['apps'] = (int) $data['count'];
                    } elseif (is_array($data) && isset($data['apps']) && is_array($data['apps'])) {
                        $payload['apps'] = count($data['apps']);
                    } elseif (!is_array($data) || empty($data['ok'])) {
                        $payload['menu_json'] = 'non_json';
                        $jsonOk = $jsonStatus >= 200 && $jsonStatus < 400;
                    }
                }
            }

            if ($this->targetAllowed($menuUrl)) {
                $result = $client->request('GET', $menuUrl, ['Accept' => 'text/html'], '', false);
                $menuStatus = (int) $result['status'];
                $menuOk = $result['error'] === null && $menuStatus >= 200 && $menuStatus < 400;
                if (!$menuOk && $result['error'] !== null && empty($payload['backend_error'])) {
                    $payload['backend_error'] = $this->stripSecrets((string) $result['error']);
                }
            }

            $payload['menu_json'] = $jsonOk ? 'ok' : 'fail';
            $payload['menu_html'] = $menuOk ? 'ok' : 'fail';
            $payload['backend_http'] = $jsonOk ? $jsonStatus : $menuStatus;
            $ok = $jsonOk || $menuOk;
            $payload['backend'] = $ok ? 'reachable' : 'unreachable';
            $payload['status'] = $ok ? 'UP' : 'DOWN';
        } catch (\Throwable $e) {
            $payload['backend'] = 'unreachable';
            $payload['status'] = 'DOWN';
            $payload['error'] = $this->stripSecrets($e->getMessage());
        }

        return $payload;
    }

    private function stripSecrets($msg)
    {
        $msg = (string) $msg;
        $msg = preg_replace('#https?://[^\s]+#i', '[backend]', $msg) ?? $msg;
        $host = (string) (parse_url($this->backendOrigin, PHP_URL_HOST) ?? '');
        if ($host !== '') {
            $msg = str_ireplace($host, '[backend]', $msg);
        }
        return $msg;
    }

    /**
     * @param list<array{0: string, 1: string}> $headerList
     */
    private function sendResponseHeaders(array $headerList, CcroRewriter $rewriter, bool $rewrittenBody, int $bodyLen): void
    {
        $sent = [];
        foreach ($headerList as [$name, $value]) {
            $lower = strtolower($name);
            if (in_array($lower, self::SKIP_RESPONSE_HEADERS, true)) {
                continue;
            }
            if ($lower === 'location' || $lower === 'refresh' || $lower === 'x-inertia-location' || $lower === 'content-location') {
                $value = $rewriter->rewriteLocation($value);
                if ($lower === 'location' || $lower === 'x-inertia-location') {
                    $rel = preg_replace('#^https?://[^/]+#i', '', $value) ?? $value;
                    // Opaque tokens were long enough for Apache to fold the header.
                    // Readable pathinfo URLs must still be sent.
                    if (str_contains($rel, '/x/') && strlen($rel) > 140) {
                        continue;
                    }
                    $value = $rel;
                }
            } elseif ($lower === 'set-cookie') {
                $value = $rewriter->rewriteCookie($value);
                if ($value === '') {
                    continue;
                }
            } else {
                $value = $rewriter->hideBackendLeak($value);
            }
            header($name . ': ' . $value, false);
            $sent[$lower] = true;
        }

        if ($rewrittenBody || !isset($sent['content-length'])) {
            header('Content-Length: ' . (string) $bodyLen, true);
        }
    }

    /**
     * @return array<string, string>
     */
    private function forwardHeaders(string $target, string $appPath = '/'): array
    {
        $out = [];
        foreach ($this->incomingHeaders() as $name => $value) {
            if (in_array(strtolower($name), self::HOP_HEADERS, true)) {
                continue;
            }
            $out[$name] = $value;
        }

        $targetHost = (string) (parse_url($target, PHP_URL_HOST) ?? '');
        $targetPort = parse_url($target, PHP_URL_PORT);
        if ($targetHost !== '') {
            $out['Host'] = $targetHost . ($targetPort ? ':' . $targetPort : '');
        }

        $out['X-Forwarded-For'] = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $out['X-Forwarded-Proto'] = str_starts_with($this->gatewayScriptUrl, 'https') ? 'https' : 'http';
        $out['X-Forwarded-Host'] = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $out['X-Ccro-Gateway'] = '1';
        $out['X-Ccro-Gateway-Url'] = $this->publicBaseUrl;
        $out['X-Ccro-Gateway-Script'] = $this->gatewayScriptUrl;
        $out['X-Ccro-Backend-Base'] = $this->backendBasePath === '/' ? '' : $this->backendBasePath;

        $publicPath = $this->gatewayPublicBasePath();
        $appDir = rtrim(str_replace('\\', '/', dirname($this->normPath($appPath))), '/');
        if ($appDir !== '' && $appDir !== '/') {
            $out['X-Forwarded-Prefix'] = ($publicPath === '' ? '' : $publicPath) . $appDir;
        }

        $token = trim((string) ($this->config['gateway_token'] ?? ''));
        if ($token !== '') {
            $out['X-Ccro-Gateway-Token'] = $token;
        }

        return $out;
    }

    /**
     * @param array<string, string> $headers
     */
    private function headerHas(array $headers, string $name): bool
    {
        $want = strtolower($name);
        foreach ($headers as $key => $unused) {
            if (strtolower((string) $key) === $want) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function incomingHeaders(): array
    {
        if (function_exists('getallheaders')) {
            /** @var array<string, string> $h */
            $h = getallheaders() ?: [];
            $hasCt = false;
            $hasCookie = false;
            foreach ($h as $key => $unused) {
                $lower = strtolower((string) $key);
                if ($lower === 'content-type') {
                    $hasCt = true;
                }
                if ($lower === 'cookie') {
                    $hasCookie = true;
                }
            }
            if (!$hasCt && !empty($_SERVER['CONTENT_TYPE'])) {
                $h['Content-Type'] = (string) $_SERVER['CONTENT_TYPE'];
            }
            if (!$hasCookie && !empty($_SERVER['HTTP_COOKIE'])) {
                $h['Cookie'] = (string) $_SERVER['HTTP_COOKIE'];
            }

            return $h;
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!str_starts_with($key, 'HTTP_')) {
                continue;
            }
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $headers[$name] = (string) $value;
        }
        if (!empty($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (!empty($_SERVER['HTTP_COOKIE']) && empty($headers['Cookie'])) {
            $headers['Cookie'] = (string) $_SERVER['HTTP_COOKIE'];
        }

        return $headers;
    }

    private function requestPath(): string
    {
        $info = (string) ($_SERVER['PATH_INFO'] ?? '');
        $raw = '';
        if ($info !== '') {
            $raw = $info;
        } else {
            $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
            $uri = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
            if ($script !== '' && str_starts_with($uri, $script)) {
                $rest = substr($uri, strlen($script));
                if (is_string($rest) && $rest !== '' && $rest !== '/') {
                    $raw = $rest;
                }
            } else {
                $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
                if ($dir !== '' && $dir !== '/' && str_starts_with($uri, $dir . '/')) {
                    $raw = substr($uri, strlen($dir));
                }
            }
        }

        $norm = $raw !== '' ? $this->normPath($raw) : '';

        // Short bookmarks: /ccrogw.php/s/v → real app path (hides long folder names).
        if ($norm !== '' && preg_match('#^/s/([A-Za-z0-9_-]+)/?$#', $norm, $sm) === 1) {
            $alias = $sm[1];
            $map = $this->config['shortcuts'] ?? [];
            if (is_array($map)) {
                foreach ($map as $key => $target) {
                    if (strcasecmp((string) $key, $alias) === 0 && is_string($target) && $target !== '') {
                        return $this->normPath($target);
                    }
                }
            }
            $this->errorPage(404, 'Unknown shortcut.');
            exit;
        }

        if ($norm !== '' && preg_match('#^/x/([A-Za-z0-9_-]+)(/.*)?$#', $norm, $m) === 1) {
            $dec = CcroOpaque::decode($this->urlKey(), $m[1]);
            if ($dec === null) {
                $fromRef = $this->pathFromReferer();
                if ($fromRef !== null && preg_match('#^/x/.+\.(php|html)$#i', $norm) === 1) {
                    return $fromRef;
                }
                $this->errorPage(404, 'Invalid or expired link.');
                exit;
            }
            $this->decodedQuery = $dec['query'];
            $basePath = $this->stripBackendPrefix($this->normPath($dec['path']));
            // Relative fetches under opaque pages become /x/TOKEN/modules/.../api.php.
            // Resolve that suffix against the encoded file's directory (e.g. /eVital/).
            $rest = isset($m[2]) && is_string($m[2]) ? $m[2] : '';
            if ($rest !== '' && $rest !== '/') {
                $baseName = basename($basePath);
                if ($baseName !== '' && preg_match('#\.[a-z0-9]{1,8}$#i', $baseName) === 1) {
                    $dir = preg_replace('#/[^/]*$#', '/', $basePath) ?? '/';
                } else {
                    $dir = rtrim($basePath, '/') . '/';
                }
                // Avoid /x/TOKEN/index.php doubling when the leaf was already index.php.
                if (strcasecmp(basename($rest), $baseName) === 0 && substr_count(trim($rest, '/'), '/') === 0) {
                    return $basePath;
                }

                return $this->normPath($dir . ltrim($rest, '/'));
            }

            return $basePath;
        }

        if ($norm !== '' && preg_match('#^/x/.+\.(php|html)$#i', $norm) === 1) {
            $fromRef = $this->pathFromReferer();
            if ($fromRef !== null) {
                return $fromRef;
            }
        }

        if ($raw !== '') {
            return $this->stripBackendPrefix($this->normPath($raw));
        }

        if (isset($_GET['p']) && is_string($_GET['p']) && $_GET['p'] !== '') {
            $this->urlMode = 'query';
            return $this->stripBackendPrefix($this->normPath($_GET['p']));
        }

        return '/';
    }

    private function stripBackendPrefix(string $path): string
    {
        $base = $this->backendBasePath;
        if ($base === '/' || $base === '') {
            return $path;
        }
        $scriptPath = (string) (parse_url($this->gatewayScriptUrl, PHP_URL_PATH) ?: '');
        $name = basename($scriptPath);
        while (str_starts_with(strtolower($path), strtolower($base) . '/')
            && ($name === '' || !str_starts_with(strtolower($path), strtolower($base) . '/' . strtolower($name)))) {
            $path = $this->normPath(substr($path, strlen($base)));
        }

        return $path;
    }

    private function pathFromReferer(): ?string
    {
        $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        if (preg_match('#/x/([A-Za-z0-9_-]+)#', $ref, $m) !== 1) {
            return null;
        }
        $dec = CcroOpaque::decode($this->urlKey(), $m[1]);
        if ($dec === null) {
            return null;
        }

        return $this->stripBackendPrefix($this->normPath($dec['path']));
    }

    private function redirectToGateway(string $path, string $query = ''): void
    {
        $rewriter = $this->rewriter('/');
        header('Location: ' . $rewriter->toGateway($path, $query), true, 302);
        exit;
    }

    private function rewriter(string $currentPath): CcroRewriter
    {
        return new CcroRewriter(
            $this->backendOrigin,
            $this->backendBasePath,
            $this->gatewayScriptUrl,
            $this->urlMode,
            $currentPath,
            $this->opaqueEnabled(),
            $this->urlKey(),
            $this->publicBaseUrl
        );
    }

    private function opaqueEnabled(): bool
    {
        return !empty($this->config['opaque_urls']) && $this->urlKey() !== '';
    }

    private function urlKey(): string
    {
        return trim((string) ($this->config['url_key'] ?? ''));
    }

    private function targetAllowed(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return false;
        }
        $want = strtolower((string) parse_url($this->backendOrigin, PHP_URL_HOST));
        $got = strtolower((string) $parts['host']);
        if ($got !== $want) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        return true;
    }

    private function isMenuPath(string $path, string $menuPath): bool
    {
        $menuDir = rtrim($menuPath, '/');
        return $path === $menuPath
            || $path === $menuDir
            || str_starts_with($path, $menuDir . '/');
    }

    private function isMenuIndex(string $path, string $menuPath): bool
    {
        $menuDir = rtrim($menuPath, '/');
        return $path === $menuPath
            || $path === $menuDir
            || $path === $menuDir . '/index.php'
            || $path === $menuDir . '/index.html';
    }

    private function shouldRewrite(string $contentType): bool
    {
        $type = strtolower($contentType);
        foreach (['html', 'css', 'javascript', 'ecmascript', 'json', 'xml', 'svg', 'text/plain'] as $needle) {
            if (str_contains($type, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function joinPath(string $base, string $path): string
    {
        if ($base === '/' || $base === '') {
            return $path === '/' ? '/' : $path;
        }

        return rtrim($base, '/') . ($path === '/' ? '/' : $path);
    }

    /** URL folder containing ccrogw.php on this XAMPP host. */
    private function gatewayPublicBasePath(): string
    {
        $scriptPath = (string) (parse_url($this->gatewayScriptUrl, PHP_URL_PATH) ?: '/ccrogw.php');
        $dir = str_replace('\\', '/', dirname($scriptPath));
        if ($dir === '/' || $dir === '.' || $dir === '') {
            return '';
        }

        return rtrim($dir, '/');
    }

    private function normPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('/\x00/', '', $path) ?? $path;
        $keepSlash = strlen($path) > 1 && substr($path, -1) === '/';
        $path = '/' . ltrim($path, '/');
        $parts = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }

        $out = '/' . implode('/', $parts);
        if ($keepSlash && $out !== '/') {
            $out .= '/';
        }

        return $out;
    }

    private function setupPage(): void
    {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Gateway setup</title>';
        echo '<style>body{font-family:Segoe UI,sans-serif;background:#0c1120;color:#dde2f0;padding:2rem;max-width:44rem;margin:auto;line-height:1.5}';
        echo 'code{background:#152040;padding:.15rem .4rem;border-radius:4px}h1{color:#d4a843}</style></head><body>';
        echo '<h1>MIS Gateway is not configured</h1>';
        echo '<p>Edit <code>config.php</code> on this server (PC2) and set <code>backend_base_url</code> to the hidden PC3 address that hosts eMenu and the web apps.</p>';
        echo '<p>Example: <code>http://10.0.0.50/ePhp</code> — that folder must contain <code>eMenu/</code> next to the other applications.</p>';
        $scriptName = htmlspecialchars(basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'ccrogw.php')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo '<p>Then open <code>' . $scriptName . '</code> again. Clients will only see this MIS host; PC3 stays internal.</p>';
        echo '</body></html>';
    }

    private function errorPage(int $code, string $message): void
    {
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
        $h = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $menu = $this->normPath((string) ($this->config['menu_path'] ?? '/eMenu/index.php'));
        $home = htmlspecialchars($this->rewriter('/')->toGateway($menu), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Gateway error</title>';
        echo '<style>body{font-family:Segoe UI,sans-serif;background:#0c1120;color:#dde2f0;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}';
        echo '.box{background:#111c35;padding:2rem;border-radius:12px;border:1px solid rgba(255,255,255,.08);max-width:28rem;text-align:center}';
        echo 'h1{color:#d4a843;margin-top:0}a{color:#4fa3e0}</style></head><body><div class="box">';
        echo '<h1>' . $code . '</h1><p>' . $h . '</p>';
        echo '<p><a href="' . $home . '">Return to portal</a></p></div></body></html>';
    }

    private function catalogCss(): string
    {
        return <<<'CSS'
:root{--ink:#06080f;--deep:#0c1120;--navy:#111c35;--panel:#152040;--line:rgba(255,255,255,.07);--gold:#d4a843;--gold2:#f0cc6e;--text:#dde2f0;--dim:#6b7590;--r:6px;--radius-md:10px;--font:"Outfit","Segoe UI",system-ui,sans-serif;--font-display:"Bebas Neue","Segoe UI",sans-serif;--font-mono:"JetBrains Mono",ui-monospace,monospace}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{font-family:var(--font);font-weight:300;font-size:15px;line-height:1.55;color:var(--text);background:var(--ink);overflow:hidden;display:flex;flex-direction:column}
#progress{position:fixed;top:0;left:0;height:2px;z-index:1000;background:linear-gradient(90deg,var(--gold),var(--gold2));width:0}
.portal-shell{width:100%;padding-left:1.25rem;padding-right:1.25rem;position:relative;z-index:2}
.portal-header{position:relative;z-index:100;flex-shrink:0;background:rgba(6,8,15,.92);backdrop-filter:blur(18px);border-bottom:1px solid var(--line);overflow:hidden}
.portal-header-bg{position:absolute;inset:0;pointer-events:none;background:radial-gradient(ellipse 60% 70% at 75% 40%,rgba(212,168,67,.07),transparent 60%)}
.portal-header-grid{position:absolute;inset:0;pointer-events:none;background-image:linear-gradient(var(--line) 1px,transparent 1px),linear-gradient(90deg,var(--line) 1px,transparent 1px);background-size:80px 80px}
.portal-shell--header{padding-top:1rem;padding-bottom:1.15rem}
.portal-top{display:flex;align-items:flex-start;justify-content:space-between;gap:1.5rem;flex-wrap:wrap;padding-bottom:1.25rem}
.brand{display:flex;align-items:center;gap:1rem}
.brand-seal{width:52px;height:52px;border-radius:var(--r);background:linear-gradient(135deg,var(--navy),var(--panel));border:1px solid rgba(212,168,67,.25);display:flex;align-items:center;justify-content:center}
.brand-seal-inner{font-family:var(--font-display);font-size:1.1rem;letter-spacing:.1em;color:var(--gold)}
.brand-text .line1{font-family:var(--font-mono);font-size:.65rem;letter-spacing:.2em;text-transform:uppercase;color:var(--gold);margin-bottom:.15rem}
.brand-text .line2{font-family:var(--font-display);font-size:1.85rem;letter-spacing:.06em;line-height:1;color:var(--text)}
.brand-text .line3{font-size:.82rem;color:var(--dim);margin-top:.35rem}
.clock{text-align:right;padding-top:.15rem}
.clock-label{font-family:var(--font-mono);font-size:.62rem;letter-spacing:.14em;text-transform:uppercase;color:var(--dim);margin-bottom:.25rem}
.clock-value{font-size:.875rem;font-weight:500;color:var(--text)}
.search-field{display:flex;align-items:center;gap:.875rem;background:var(--deep);border:1px solid var(--line);border-radius:var(--r);padding:.9rem 1.25rem}
.search-field:focus-within{border-color:rgba(212,168,67,.35)}
.search-field svg{color:var(--gold)}
.search-field input{border:0;outline:0;flex:1;font-size:.9rem;font-family:inherit;min-width:0;color:var(--text);background:transparent}
.search-field input::placeholder{color:var(--dim)}
.portal-main{position:relative;z-index:1;flex:1;min-height:0;overflow-y:auto;padding:1.25rem 0 2rem}
.notice{color:#f0cc6e;margin-bottom:1rem;font-size:.9rem}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:1.2rem}
.app-card{display:flex;flex-direction:column;align-items:center;text-align:center;text-decoration:none;color:inherit;padding:1.55rem 1rem 1.4rem;border:1px solid var(--line);border-radius:var(--radius-md);background:linear-gradient(180deg,var(--navy),var(--deep));min-height:196px;transition:transform .22s,border-color .2s}
.app-card:hover{transform:translateY(-3px);border-color:rgba(212,168,67,.35)}
.app-card[hidden]{display:none!important}
.app-icon{width:70px;height:70px;border-radius:50%;background:linear-gradient(155deg,var(--panel),var(--navy));border:1px solid rgba(212,168,67,.25);display:flex;align-items:center;justify-content:center;font-weight:800;color:var(--gold);margin-bottom:1rem;overflow:hidden}
.app-icon img{width:100%;height:100%;object-fit:contain;padding:.35rem}
.app-title{font-size:.9375rem;font-weight:700;margin:0 0 .4rem}
.app-sub{font-size:.6875rem;color:var(--dim);margin:0;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;flex:1}
.empty-state{grid-column:1/-1;text-align:center;padding:3rem 1rem;color:var(--dim)}
.visually-hidden{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);border:0}
@media(max-width:640px){.portal-top{flex-direction:column}.clock{text-align:left;width:100%}.grid{grid-template-columns:repeat(auto-fill,minmax(144px,1fr))}}
@media(min-width:1200px){.grid{grid-template-columns:repeat(6,1fr)}}
CSS;
    }

    private function catalogJs(): string
    {
        return <<<'JS'
(function(){
  window.__gwLogoFallback=function(img){var icon=img.parentNode;if(!icon)return;var abbr=img.getAttribute("data-abbr")||"?";icon.removeChild(img);icon.textContent=abbr;};
  var search=document.getElementById("portal-search");
  var cards=document.querySelectorAll(".app-card");
  var empty=document.getElementById("empty-state");
  var progress=document.getElementById("progress");
  var main=document.querySelector(".portal-main");
  function norm(s){return (s||"").toLowerCase().trim();}
  function apply(){
    if(!search)return;
    var q=norm(search.value),visible=0;
    cards.forEach(function(card){
      var show=!q||norm(card.getAttribute("data-title")).indexOf(q)!==-1||norm(card.getAttribute("data-subtitle")).indexOf(q)!==-1;
      card.hidden=!show; if(show) visible++;
    });
    if(empty) empty.hidden=visible!==0;
  }
  if(search) search.addEventListener("input",apply);
  function updateProgress(){
    if(!progress||!main)return;
    var max=main.scrollHeight-main.clientHeight;
    progress.style.width=max>0?(main.scrollTop/max*100)+"%":"0%";
  }
  if(main) main.addEventListener("scroll",updateProgress,{passive:true});
  updateProgress();
})();
JS;
    }
}

$configFile = __DIR__ . '/config.php';

if (isset($_GET['ccro_health'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $payload = [
        'status' => 'DOWN',
        'build' => 'xampp-elcr',
        'php' => PHP_VERSION,
        'curl' => function_exists('curl_init'),
        'config' => is_file($configFile) ? 'present' : 'missing',
        'backend' => 'not_checked',
        'timestamp' => gmdate('c'),
    ];

    if (!is_file($configFile)) {
        $payload['error'] = 'config.php is missing next to ccrogw.php.';
        echo json_encode($payload);
        exit;
    }

    $config = require $configFile;
    if (!is_array($config)) {
        $payload['config'] = 'invalid';
        $payload['error'] = 'config.php must return an array.';
        echo json_encode($payload);
        exit;
    }

    $gw = new CcroGateway($config);
    echo json_encode($gw->healthReport($payload), JSON_UNESCAPED_SLASHES);
    exit;
}

if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Gateway is not configured. Place config.php next to ccrogw.php.';
    exit;
}

$config = require $configFile;
if (!is_array($config)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid config.php';
    exit;
}

(new CcroGateway($config))->run();
