<?php

declare(strict_types=1);

namespace Tivins\Llama;

/**
 * Predefined tools for the Llama API.
 *
 * Tool `parameters` in {@see ChatFunctionTool} must be a JSON Schema object (`type`,
 * `properties`, `required`, …). Shorthand maps like `['file_path' => 'string']` are not valid
 * schema and may cause models to emit arbitrary argument keys (`path`, `filename`, …).
 * See {@see examples/chat_tools.php} for the same pattern.
 *
 * Outbound HTTPS ({@see webSearch}, {@see fetchWebPage}): TLS verification defaults to strict.
 * On hosts without a CA bundle (common on Windows PHP), set `php.ini` `curl.cainfo`, or env
 * `TIVINS_LLAMA_CURL_CAINFO` to a PEM file (e.g. from https://curl.se/ca/cacert.pem ).
 * On **Windows**, when `TIVINS_LLAMA_CURL_CAINFO` is **not** set (or unreadable), PHP 8.2+ may enable
 * `CURLSSLOPT_NATIVE_CA` so system roots apply (helps corporate TLS inspection).
 * Set env `TIVINS_LLAMA_CURL_WINDOWS_NATIVE_CA=1` to prefer that store even if `TIVINS_LLAMA_CURL_CAINFO`
 * is set (mozilla-only bundles often fail behind intercepting proxies); `=0` disables native CA.
 * Call {@see setHttpSslVerifyPeer}(false) only on trusted networks (insecure).
 * Env `TIVINS_LLAMA_HTTP_SSL_VERIFY`: `0` / `false` / `no` / `off` disables verification.
 */
class PredefinedTools
{
    private static bool $httpSslVerifyPeer = true;

    /**
     * When false, disables TLS certificate verification for {@see webSearch} and {@see fetchWebPage}
     * (vulnerable to MITM — use only on trusted networks). Env `TIVINS_LLAMA_HTTP_SSL_VERIFY`
     * overrides when set (`0`, `false`, `no`, `off` → disable).
     */
    public static function setHttpSslVerifyPeer(bool $verify): void
    {
        self::$httpSslVerifyPeer = $verify;
    }

    public static function getReadFileTool(): ChatFunctionTool
    {
        return new ChatFunctionTool(
            'read_file',
            'Read the contents of a file.',
            [
                'type' => 'object',
                'properties' => [
                    'file_path' => [
                        'type' => 'string',
                        'description' => 'Path of the file to read.',
                    ],
                ],
                'required' => ['file_path'],
                'additionalProperties' => false,
            ],
        );
    }

    public static function getWriteFileTool(): ChatFunctionTool
    {
        return new ChatFunctionTool(
            'write_file',
            'Write text to a file.',
            [
                'type' => 'object',
                'properties' => [
                    'file_path' => [
                        'type' => 'string',
                        'description' => 'Path of the file to write.',
                    ],
                    'content' => [
                        'type' => 'string',
                        'description' => 'Full content to write.',
                    ],
                ],
                'required' => ['file_path', 'content'],
                'additionalProperties' => false,
            ],
        );
    }

    public static function getDateTimeTool(): ChatFunctionTool
    {
        return new ChatFunctionTool(
            'get_date_time',
            'Get the current date and time in the format YYYY-MM-DD HH:MM:SS.',
            [
                'type' => 'object',
                'properties' => [],
                'required' => [],
                'additionalProperties' => false,
            ],
        );
    }

    public static function getGrepTool(): ChatFunctionTool
    {
        return new ChatFunctionTool(
            'grep',
            'Find text in files under a path and return matching lines with file and line number.',
            [
                'type' => 'object',
                'properties' => [
                    'pattern' => [
                        'type' => 'string',
                        'description' => 'Text to search for when literal=true, or a full PCRE pattern including delimiters when literal=false.',
                    ],
                    'path' => [
                        'type' => 'string',
                        'description' => 'File or directory to search.',
                    ],
                    'literal' => [
                        'type' => 'boolean',
                        'description' => 'If true, treat pattern as plain text (default). If false, pattern must be a valid PCRE pattern with delimiters.',
                        'default' => true,
                    ],
                    'max_matches' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of matching lines to return (default 500, max 5000).',
                        'default' => 500,
                    ],
                    'max_depth' => [
                        'type' => 'integer',
                        'description' => 'Maximum directory depth when path is a directory (default 20).',
                        'default' => 20,
                    ],
                ],
                'required' => ['pattern', 'path'],
                'additionalProperties' => false,
            ],
        );
    }

    public static function getWebSearchTool(): ChatFunctionTool
    {
        return new ChatFunctionTool(
            'web_search',
            'Search the web via DuckDuckGo HTML and return real result entries (title, url, snippet). Use fetch_web_page to read the full body of a returned URL.',
            [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Search query.',
                    ],
                    'max_results' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of results to return (default 8, max 20).',
                        'default' => 8,
                    ],
                ],
                'required' => ['query'],
                'additionalProperties' => false,
            ],
        );
    }

    public static function getFetchWebPageTool(): ChatFunctionTool
    {
        return new ChatFunctionTool(
            'fetch_web_page',
            'Fetch a document over HTTP GET only (http/https). Response body may be truncated to stay within max_bytes. '
            . 'For HTML pages, the body is returned as plain text by default (scripts/styles removed) to save context; set raw_html true only when tag structure is required.',
            [
                'type' => 'object',
                'properties' => [
                    'url' => [
                        'type' => 'string',
                        'description' => 'Absolute URL (http or https).',
                    ],
                    'max_bytes' => [
                        'type' => 'integer',
                        'description' => 'Maximum bytes of body to retain (default 524288, min 1024, max 2097152).',
                        'default' => 524288,
                    ],
                    'raw_html' => [
                        'type' => 'boolean',
                        'description' => 'If true, return the raw response body unchanged. If false (default), HTML/XHTML responses are collapsed to visible plain text.',
                        'default' => false,
                    ],
                ],
                'required' => ['url'],
                'additionalProperties' => false,
            ],
        );
    }

    public static function getApplyDiffTool(): ChatFunctionTool
    {
        return new ChatFunctionTool(
            'apply_diff',
            'Apply a unified diff using the patch program (must be installed and on PATH). '
            . 'Omit strip for automatic retries (detects Git-style paths vs plain filenames; handles common CRLF/LF mismatches on Windows). '
            . 'A trailing newline is appended to the diff text if missing before invoking patch. '
            . 'On success, the JSON includes stdout, stderr (often empty), and warnings[] (non-fatal patch diagnostics parsed from those streams).',
            [
                'type' => 'object',
                'properties' => [
                    'diff' => [
                        'type' => 'string',
                        'description' => 'Unified diff text to apply.',
                    ],
                    'working_directory' => [
                        'type' => 'string',
                        'description' => 'Directory in which to run patch (repository root).',
                    ],
                    'strip' => [
                        'type' => 'integer',
                        'description' => 'Path strip count for patch -p. Omit to auto-guess (-p1 for a/ prefixes, else -p0 first) and retry with fallbacks.',
                    ],
                ],
                'required' => ['diff', 'working_directory'],
                'additionalProperties' => false,
            ],
        );
    }

    public static function getGitStatusTool(): ChatFunctionTool
    {
        return new ChatFunctionTool(
            'git_status',
            'Run git status --porcelain in a working directory.',
            [
                'type' => 'object',
                'properties' => [
                    'working_directory' => [
                        'type' => 'string',
                        'description' => 'Path to the git working tree.',
                    ],
                ],
                'required' => ['working_directory'],
                'additionalProperties' => false,
            ],
        );
    }

    public static function getRunPhpUnitTool(): ChatFunctionTool
    {
        return new ChatFunctionTool(
            'run_phpunit',
            'Run PHPUnit via PHP (php path/to/phpunit). Requires PHPUnit at the given path.',
            [
                'type' => 'object',
                'properties' => [
                    'working_directory' => [
                        'type' => 'string',
                        'description' => 'Directory to run the command from (e.g. project root).',
                    ],
                    'phpunit_path' => [
                        'type' => 'string',
                        'description' => 'Path to phpunit relative to working_directory or absolute (default vendor/bin/phpunit).',
                        'default' => 'vendor/bin/phpunit',
                    ],
                    'arguments' => [
                        'type' => 'array',
                        'description' => 'Extra CLI arguments for PHPUnit (strings).',
                        'items' => ['type' => 'string'],
                    ],
                ],
                'required' => ['working_directory'],
                'additionalProperties' => false,
            ],
        );
    }

    public static function all(): array
    {
        return [
            'read_file' => self::getReadFileTool(),
            'write_file' => self::getWriteFileTool(),
            'get_date_time' => self::getDateTimeTool(),
            'grep' => self::getGrepTool(),
            'web_search' => self::getWebSearchTool(),
            'fetch_web_page' => self::getFetchWebPageTool(),
            'apply_diff' => self::getApplyDiffTool(),
            'git_status' => self::getGitStatusTool(),
            'run_phpunit' => self::getRunPhpUnitTool(),
        ];
    }

    /**
     * @return array<string, callable(array): mixed>
     */
    public static function getExecuteTools(): array
    {
        return [
            'read_file' => static fn (array $parameters): string|false => self::readFile($parameters),
            'write_file' => static fn (array $parameters): bool => self::writeFile($parameters),
            'get_date_time' => static fn (array $_): string => self::getDateTime(),
            'grep' => static fn (array $parameters): array => self::grep($parameters),
            'web_search' => static fn (array $parameters): array => self::webSearch($parameters),
            'fetch_web_page' => static fn (array $parameters): array => self::fetchWebPage($parameters),
            'apply_diff' => static fn (array $parameters): array => self::applyDiff($parameters),
            'git_status' => static fn (array $parameters): array => self::gitStatus($parameters),
            'run_phpunit' => static fn (array $parameters): array => self::runPhpUnit($parameters),
        ];
    }

    /**
     * Runs a known tool and returns content suitable for a {@see Role::Tool} message.
     * Success for `read_file` is raw file text; other tools return JSON strings for consistency.
     *
     * @throws \JsonException when encoding an error payload fails
     */
    public static function runTool(string $name, array $parameters): string
    {
        $tools = self::getExecuteTools();
        if (!isset($tools[$name])) {
            return json_encode(['error' => 'unknown tool'], JSON_THROW_ON_ERROR);
        }

        try {
            $result = $tools[$name]($parameters);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR);
        }

        return self::formatToolResult($name, $result);
    }

    private static function formatToolResult(string $name, mixed $result): string
    {
        return match ($name) {
            'read_file' => $result === false
                ? json_encode(['error' => 'failed to read file'], JSON_THROW_ON_ERROR)
                : (string) $result,
            'write_file' => $result === false
                ? json_encode(['error' => 'failed to write file'], JSON_THROW_ON_ERROR)
                : json_encode(['ok' => true], JSON_THROW_ON_ERROR),
            'get_date_time' => (string) $result,
            default => json_encode(
                is_array($result) ? $result : ['result' => $result],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
        };
    }

    public static function readFile(array $parameters): string|false
    {
        $path = $parameters['file_path'] ?? '';
        if (!is_string($path) || $path === '') {
            return false;
        }

        $data = @file_get_contents($path);

        return $data === false ? false : $data;
    }

    public static function writeFile(array $parameters): bool
    {
        $path = $parameters['file_path'] ?? '';
        if (!is_string($path) || $path === '') {
            return false;
        }

        return @file_put_contents($path, $parameters['content'] ?? '') !== false;
    }

    public static function getDateTime(array $parameters = []): string
    {
        return date('Y-m-d H:i:s e');
    }

    /**
     * @return array<string, mixed>
     */
    public static function grep(array $parameters): array
    {
        $pattern = $parameters['pattern'] ?? '';
        $path = $parameters['path'] ?? '';
        if (!is_string($pattern) || $pattern === '' || !is_string($path) || $path === '') {
            return ['error' => 'pattern and path must be non-empty strings'];
        }

        $literal = true;
        if (array_key_exists('literal', $parameters)) {
            $literal = filter_var($parameters['literal'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($literal === null) {
                return ['error' => 'literal must be a boolean'];
            }
        }

        $maxMatches = isset($parameters['max_matches']) ? (int) $parameters['max_matches'] : 500;
        $maxMatches = max(1, min(5000, $maxMatches));

        $maxDepth = isset($parameters['max_depth']) ? (int) $parameters['max_depth'] : 20;
        $maxDepth = max(0, min(100, $maxDepth));

        $regex = $literal ? '/' . str_replace('/', '\/', preg_quote($pattern, '/')) . '/u' : $pattern;
        @preg_match($regex, '');
        if (preg_last_error() !== PREG_NO_ERROR) {
            return ['error' => 'invalid search pattern'];
        }

        if (!file_exists($path)) {
            return ['error' => 'path does not exist'];
        }

        $matches = [];
        $realPath = realpath($path);
        if ($realPath === false) {
            return ['error' => 'could not resolve path'];
        }

        if (is_file($realPath)) {
            self::grepFile($realPath, $regex, $matches, $maxMatches);

            return ['matches' => $matches];
        }

        if (!is_dir($realPath)) {
            return ['error' => 'path is not a readable file or directory'];
        }

        $seenFiles = 0;
        self::grepDirectory($realPath, $regex, $matches, $maxMatches, $maxDepth, 0, $seenFiles);

        return ['matches' => $matches];
    }

    /**
     * @param list<array{file: string, line: int, text: string}> $matches
     */
    private static function grepFile(string $file, string $regex, array &$matches, int $maxMatches): void
    {
        if (count($matches) >= $maxMatches) {
            return;
        }

        $sample = @file_get_contents($file, false, null, 0, 8192);
        if ($sample === false) {
            return;
        }
        if (str_contains($sample, "\0")) {
            return;
        }

        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            return;
        }

        $lineNum = 0;
        while (($line = fgets($fh)) !== false && count($matches) < $maxMatches) {
            ++$lineNum;
            if (@preg_match($regex, $line) === 1) {
                $matches[] = [
                    'file' => $file,
                    'line' => $lineNum,
                    'text' => rtrim($line, "\r\n"),
                ];
            }
        }
        fclose($fh);
    }

    /**
     * @param list<array{file: string, line: int, text: string}> $matches
     */
    private static function grepDirectory(
        string $dir,
        string $regex,
        array &$matches,
        int $maxMatches,
        int $maxDepth,
        int $depth,
        int &$seenFiles,
        int $maxFiles = 2000,
    ): void {
        if ($depth > $maxDepth || count($matches) >= $maxMatches || $seenFiles >= $maxFiles) {
            return;
        }

        $items = @scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($full)) {
                self::grepDirectory($full, $regex, $matches, $maxMatches, $maxDepth, $depth + 1, $seenFiles, $maxFiles);
                continue;
            }
            if (!is_file($full) || !is_readable($full)) {
                continue;
            }
            ++$seenFiles;
            self::grepFile($full, $regex, $matches, $maxMatches);
        }
    }

    /**
     * TLS options for {@see webSearch} and {@see fetchWebPage}.
     *
     * @return array<int, mixed>
     */
    private static function httpSslCurlOpts(): array
    {
        $verify = self::$httpSslVerifyPeer;
        $envVerify = getenv('TIVINS_LLAMA_HTTP_SSL_VERIFY');
        if ($envVerify !== false && (string) $envVerify !== '') {
            $verify = !in_array(strtolower((string) $envVerify), ['0', 'false', 'no', 'off'], true);
        }

        $opts = [
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        ];

        $cainfoEnv = getenv('TIVINS_LLAMA_CURL_CAINFO');
        $cainfoUsable = is_string($cainfoEnv) && $cainfoEnv !== '' && is_readable($cainfoEnv);

        $nativeCaDisabled = getenv('TIVINS_LLAMA_CURL_WINDOWS_NATIVE_CA') === '0';
        $nativeCaForced = getenv('TIVINS_LLAMA_CURL_WINDOWS_NATIVE_CA') === '1';

        $useWindowsNativeCa = $verify
            && PHP_OS_FAMILY === 'Windows'
            && defined('CURLSSLOPT_NATIVE_CA')
            && !$nativeCaDisabled
            && (!$cainfoUsable || $nativeCaForced);

        if ($useWindowsNativeCa) {
            $opts[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        }

        if ($cainfoUsable && (!$nativeCaForced || !$useWindowsNativeCa)) {
            $opts[CURLOPT_CAINFO] = $cainfoEnv;
        }

        return $opts;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function maybeAddSslHint(array &$payload, string $curlError): void
    {
        $msg = strtolower($curlError);
        if ($msg === '') {
            return;
        }

        if (
            !str_contains($msg, 'ssl')
            && !str_contains($msg, 'certificate')
            && !str_contains($msg, 'openssl')
        ) {
            return;
        }

        $payload['hint'] = 'Corporate TLS inspection often breaks mozilla-only CA bundles ('
            . '"self-signed certificate in certificate chain"). '
            . 'On Windows, omit `TIVINS_LLAMA_CURL_CAINFO` so the OS certificate store can be used '
            . '(PHP 8.2+ with CURLSSLOPT_NATIVE_CA), or set `TIVINS_LLAMA_CURL_WINDOWS_NATIVE_CA=1` '
            . 'to prefer that store even when `TIVINS_LLAMA_CURL_CAINFO` is set. '
            . 'Alternatively append your organization root CA to the PEM file, or '
            . '`PredefinedTools::setHttpSslVerifyPeer(false)` on a trusted network only.';
    }

    /**
     * Fetches DuckDuckGo HTML search results and parses real result entries.
     *
     * The legacy JSON Instant Answer API (`api.duckduckgo.com`) returns empty data for most
     * queries; the HTML endpoint (`html.duckduckgo.com/html/`) always returns ranked results.
     *
     * @return array<string, mixed>
     */
    public static function webSearch(array $parameters): array
    {
        $query = $parameters['query'] ?? '';
        if (!is_string($query) || $query === '') {
            return ['error' => 'query must be a non-empty string'];
        }

        $maxResults = isset($parameters['max_results']) ? (int) $parameters['max_results'] : 8;
        $maxResults = max(1, min(20, $maxResults));

        $url = 'https://html.duckduckgo.com/html/?' . http_build_query(['q' => $query]);

        $ch = curl_init($url);
        if ($ch === false) {
            return ['error' => 'could not initialize HTTP client'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; tivins/llm-php; +https://github.com/tivins/llm-php)',
        ] + self::httpSslCurlOpts());

        $body = curl_exec($ch);
        $err = curl_error($ch);
        $errno = curl_errno($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $body === '') {
            $payload = ['error' => $err !== '' ? $err : 'empty response', 'http_status' => $code];
            if ($errno !== 0) {
                $payload['curl_errno'] = $errno;
            }
            self::maybeAddSslHint($payload, $err);

            return $payload;
        }

        if ($code !== 200) {
            return ['error' => 'unexpected HTTP status', 'http_status' => $code];
        }

        $results = self::parseDdgHtmlResults((string) $body, $maxResults);

        return ['results' => $results, 'http_status' => $code];
    }

    /**
     * Parses the result title links and snippet elements from a DuckDuckGo HTML search page.
     *
     * Each result link has the form `/l/?uddg=ENCODED_URL&…`; snippets follow in matching order.
     *
     * @return list<array{title: string, url: string, snippet: string}>
     */
    private static function parseDdgHtmlResults(string $html, int $max): array
    {
        $results = [];

        // Title links: `<a … class="result__a" href="//duckduckgo.com/l/?uddg=ENCODED_URL&amp;…">Title</a>`
        // The href prefix is `//duckduckgo.com/l/?uddg=`; `&amp;` separates the URL from the `rut` param.
        preg_match_all(
            '/<a\b[^>]*\bclass="result__a"[^>]*\bhref="[^"]*\/l\/\?uddg=([^"&]+)[^"]*"[^>]*>(.*?)<\/a>/si',
            $html,
            $titleMatches,
            PREG_SET_ORDER,
        );

        // Snippets: `<a … class="result__snippet" …>Text</a>`
        preg_match_all(
            '/<a\b[^>]*\bclass="result__snippet"[^>]*>(.*?)<\/a>/si',
            $html,
            $snippetMatches,
            PREG_SET_ORDER,
        );

        foreach ($titleMatches as $i => $m) {
            if (count($results) >= $max) {
                break;
            }

            $url = urldecode($m[1]);
            if (!str_starts_with($url, 'http')) {
                continue;
            }

            $title = trim(preg_replace(
                '/\s+/',
                ' ',
                strip_tags(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            ) ?? '');

            if ($title === '') {
                continue;
            }

            $snippet = '';
            if (isset($snippetMatches[$i][1])) {
                $snippet = trim(preg_replace(
                    '/\s+/',
                    ' ',
                    strip_tags(html_entity_decode($snippetMatches[$i][1], ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                ) ?? '');
            }

            $results[] = ['title' => $title, 'url' => $url, 'snippet' => $snippet];
        }

        return $results;
    }

    /**
     * Reduces HTML/XHTML-like responses to plain visible text so tool results stay smaller in LLM context.
     */
    private static function htmlResponseToPlainText(string $html): string
    {
        $stripBlock = static function (string $markup, string $tag): string {
            $pattern = '#<' . $tag . '\b[^>]*>.*?</' . $tag . '>#is';

            return preg_replace($pattern, ' ', $markup) ?? $markup;
        };

        $html = $stripBlock($html, 'script');
        $html = $stripBlock($html, 'style');
        $html = $stripBlock($html, 'noscript');
        $html = $stripBlock($html, 'template');
        $html = preg_replace('#<script\b[^>]*>.*$#is', ' ', $html) ?? $html;
        $html = preg_replace('#<style\b[^>]*>.*$#is', ' ', $html) ?? $html;
        $html = preg_replace('#<!--.*?-->#s', ' ', $html) ?? $html;
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private static function httpResponseLooksHtml(string $contentType, string $body): bool
    {
        $ct = strtolower($contentType);
        if ($ct !== '') {
            if (str_contains($ct, 'text/html') || str_contains($ct, 'application/xhtml+xml')) {
                return true;
            }
            if (
                str_contains($ct, 'json')
                || (str_contains($ct, 'xml') && !str_contains($ct, 'html'))
                || str_starts_with($ct, 'image/')
                || str_starts_with($ct, 'audio/')
                || str_starts_with($ct, 'video/')
                || str_contains($ct, 'octet-stream')
            ) {
                return false;
            }
        }

        $trim = ltrim($body);

        return str_starts_with($trim, '<')
            && preg_match('/^<\s*(!DOCTYPE|html|head|body|div|span|main|article|section)\b/i', $trim) === 1;
    }

    /**
     * HTTP GET only; schemes limited to http/https; response body length is capped by the max_bytes parameter.
     *
     * @return array<string, mixed>
     */
    public static function fetchWebPage(array $parameters): array
    {
        $url = $parameters['url'] ?? '';
        if (!is_string($url) || $url === '') {
            return ['error' => 'url must be a non-empty string'];
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return ['error' => 'invalid URL'];
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return ['error' => 'only http and https URLs are allowed'];
        }

        $rawHtml = filter_var($parameters['raw_html'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $maxBytes = isset($parameters['max_bytes']) ? (int) $parameters['max_bytes'] : 524_288;
        $maxBytes = max(1024, min(2 * 1024 * 1024, $maxBytes));

        $body = '';
        $truncated = false;

        $ch = curl_init($url);
        if ($ch === false) {
            return ['error' => 'could not initialize HTTP client'];
        }

        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_USERAGENT => 'tivins/llm-php (PredefinedTools; +https://github.com/tivins/llm-php)',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_ENCODING => '',
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$truncated, $maxBytes): int {
                $len = strlen($chunk);
                $have = strlen($body);
                if ($have >= $maxBytes) {
                    $truncated = true;

                    return 0;
                }

                $space = $maxBytes - $have;
                if ($len <= $space) {
                    $body .= $chunk;
                } else {
                    $body .= substr($chunk, 0, $space);
                    $truncated = true;
                }

                return $len;
            },
        ] + self::httpSslCurlOpts());

        curl_exec($ch);
        $err = curl_error($ch);
        $errno = curl_errno($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $effective = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($body === '' && $errno !== 0) {
            $payload = ['error' => $err !== '' ? $err : 'request failed', 'curl_errno' => $errno];
            self::maybeAddSslHint($payload, $err);

            return $payload;
        }

        $textExtracted = false;
        if (!$rawHtml && self::httpResponseLooksHtml($ctype, $body)) {
            $body = self::htmlResponseToPlainText($body);
            $textExtracted = true;
        }

        return [
            'url' => $effective !== '' ? $effective : $url,
            'http_status' => $code,
            'content_type' => $ctype,
            'truncated' => $truncated,
            'text_extracted' => $textExtracted,
            'body' => $body,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function applyDiff(array $parameters): array
    {
        $diffRaw = $parameters['diff'] ?? '';
        $cwd = $parameters['working_directory'] ?? '';
        if (!is_string($diffRaw) || $diffRaw === '' || !is_string($cwd) || $cwd === '') {
            return ['error' => 'diff and working_directory are required'];
        }

        $diff = str_replace(["\r\n", "\r"], "\n", $diffRaw);
        $diff = self::ensureUnifiedDiffEndsWithNewline($diff);

        $real = realpath($cwd);
        if ($real === false || !is_dir($real)) {
            return ['error' => 'working_directory is not a directory'];
        }

        $stripExplicit = null;
        if (array_key_exists('strip', $parameters)) {
            $s = filter_var($parameters['strip'], FILTER_VALIDATE_INT);
            $stripExplicit = $s !== false ? max(0, min(10, $s)) : null;
        }

        $candidateStrips = $stripExplicit !== null
            ? self::uniqueInts(array_merge([$stripExplicit], self::fallbackStripCandidates($diff)))
            : self::uniqueInts(array_merge(self::guessStripLevelsFromDiff($diff), range(0, 3)));

        $useCrlf = self::diffTargetsAppearCrlfOnDisk($diff, $real);

        /** @var list<array{label: string, patch: string, args: list<string>}> */
        $strategies = [];
        $strategies[] = ['label' => 'text', 'patch' => $diff, 'args' => ['patch', '--batch', '--forward']];
        $strategies[] = [
            'label' => 'ignore_space',
            'patch' => $diff,
            'args' => ['patch', '--batch', '--forward', '--ignore-whitespace'],
        ];

        if ($useCrlf) {
            $crlfDiff = self::ensureUnifiedDiffEndsWithNewline(
                self::rewriteUnifiedDiffBodyForTargetNewlines($diff, "\r\n"),
            );
            $strategies[] = ['label' => 'crlf_body', 'patch' => $crlfDiff, 'args' => ['patch', '--batch', '--forward']];
            $strategies[] = [
                'label' => 'crlf_body_ignore_ws',
                'patch' => $crlfDiff,
                'args' => ['patch', '--batch', '--forward', '--ignore-whitespace'],
            ];
        }

        $lastReject = ['exit_code' => -1, 'stdout' => '', 'stderr' => ''];

        foreach ($candidateStrips as $strip) {
            foreach ($strategies as $strategy) {
                $args = [...$strategy['args'], '-p' . $strip];
                $run = self::runProcess($args, $real, $strategy['patch']);

                $lastReject = [
                    'exit_code' => $run['exit_code'],
                    'stdout' => trim($run['stdout']),
                    'stderr' => trim($run['stderr']),
                ];

                if ($run['exit_code'] === 0) {
                    $sep = self::separatePatchWarningsFromProcessOutput($run['stdout'], $run['stderr']);
                    $applied = trim($sep['stdout']);
                    if ($applied === '') {
                        $applied = 'patch succeeded';
                    }

                    return [
                        'ok' => true,
                        'stdout' => $applied,
                        'stderr' => trim($sep['stderr']),
                        'warnings' => $sep['warnings'],
                        'strip_used' => $strip,
                        'strategy' => $strategy['label'],
                    ];
                }
            }
        }

        return [
            'ok' => false,
            'exit_code' => $lastReject['exit_code'],
            'stdout' => $lastReject['stdout'],
            'stderr' => $lastReject['stderr'],
            'hints' => self::patchFailureHints(),
        ];
    }

    /**
     * GNU patch expects unified diff stdin to end with a newline; without it, some versions print noisy warnings
     * (e.g. "patch unexpectedly ends in middle of line") even when the patch applies.
     */
    private static function ensureUnifiedDiffEndsWithNewline(string $diff): string
    {
        if ($diff === '') {
            return $diff;
        }

        return str_ends_with($diff, "\n") ? $diff : ($diff . "\n");
    }

    /**
     * Exact lines emitters sometimes print to stdout or stderr that represent benign patch diagnostics.
     *
     * @return list<string>
     */
    private static function patchKnownStdoutStderrWarningLines(): array
    {
        return [
            'patch unexpectedly ends in middle of line',
        ];
    }

    /**
     * Split process output into cleaned streams plus extracted warning lines (deduplicated from both).
     *
     * @return array{stdout: string, stderr: string, warnings: list<string>}
     */
    private static function separatePatchWarningsFromProcessOutput(string $stdout, string $stderr): array
    {
        $known = self::patchKnownStdoutStderrWarningLines();
        /** @var list<string> */
        $warnings = [];

        $filterBlock = function (string $block) use ($known, &$warnings): string {
            $norm = str_replace("\r\n", "\n", str_replace("\r", "\n", $block));
            /** @var list<string> */
            $kept = [];

            foreach (explode("\n", $norm) as $line) {
                if ($line !== '' && in_array($line, $known, true)) {
                    $warnings[] = $line;

                    continue;
                }
                $kept[] = $line;
            }

            return rtrim(implode("\n", $kept), "\n");
        };

        return [
            'stdout' => $filterBlock($stdout),
            'stderr' => $filterBlock($stderr),
            'warnings' => $warnings,
        ];
    }

    /**
     * @param list<int> $ints
     * @return list<int>
     */
    private static function uniqueInts(array $ints): array
    {
        $seen = [];
        $out = [];
        foreach ($ints as $v) {
            if (isset($seen[$v])) {
                continue;
            }
            $seen[$v] = true;
            $out[] = $v;
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    private static function fallbackStripCandidates(string $diff): array
    {
        $guess = self::guessStripLevelsFromDiff($diff);

        return self::uniqueInts(array_merge($guess, [0, 1, 2, 3]));
    }

    /**
     * Inspect unified diff paths to order plausible -p strip counts (fewer guesses first).
     *
     * @return list<int>
     */
    private static function guessStripLevelsFromDiff(string $diffLf): array
    {
        $paths = self::pathsFromUnifiedDiffPaths($diffLf);
        foreach ($paths as $rel) {
            if (preg_match('#^(?:a|b)/.+#', $rel) === 1) {
                return [1, 0, 2];
            }
            if (str_contains($rel, '/') || str_contains($rel, '\\')) {
                return [1, 0, 2];
            }
            if ($rel !== '') {
                return [0, 1];
            }
        }

        return [0, 1, 2];
    }

    /**
     * Paths from unified diff "---" lines (preferred for heuristic; skips /dev/null).
     *
     * @return list<string>
     */
    private static function pathsFromUnifiedDiffPaths(string $diffLf): array
    {
        preg_match_all('/^--- (.+)$/m', $diffLf, $m);

        /** @var list<string> */
        $out = [];
        if (!isset($m[1]) || !is_array($m[1])) {
            return $out;
        }

        foreach ($m[1] as $rawLine) {
            if (!is_string($rawLine)) {
                continue;
            }

            // Use the first tab-separated field (GNU diff appends mtime after a tab on the same line).
            $firstField = preg_split("#\t#", $rawLine, 2)[0] ?? $rawLine;
            $pathTrim = trim($firstField);
            if (preg_match('#^"(.+)"$#', $pathTrim, $q) === 1 && isset($q[1])) {
                $pathTrim = $q[1];
            }

            $pathTrim = ltrim(str_replace('\\', '/', $pathTrim));

            if ($pathTrim === '' || str_starts_with($pathTrim, '/dev/null')) {
                continue;
            }

            $out[] = $pathTrim;
        }

        return $out;
    }

    /**
     * True if some target files under cwd look CRLF-encoded (inspect first path found in "---" headers).
     */
    private static function diffTargetsAppearCrlfOnDisk(string $diffLf, string $workingReal): bool
    {
        foreach (self::pathsFromUnifiedDiffPaths($diffLf) as $rel) {
            /** @var list<string> */
            $candidates = [];

            $candidates[] = $workingReal . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
            if (preg_match('#^(?:a|b)/(.*)$#D', $rel, $nm) === 1 && isset($nm[1])) {
                $candidates[] = $workingReal . DIRECTORY_SEPARATOR
                    . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $nm[1]);
            }

            foreach ($candidates as $absolute) {
                if (!is_file($absolute)) {
                    continue;
                }
                $sample = file_get_contents($absolute, false, null, 0, 8192);

                return is_string($sample) && str_contains($sample, "\r\n");
            }
        }

        return false;
    }

    /**
     * Embed CRLF inside hunk payload lines while keeping LF record separators — helps patch against CRLF-on-disk targets.
     */
    private static function rewriteUnifiedDiffBodyForTargetNewlines(string $diffLf, string $newline): string
    {
        if ($newline !== "\r\n") {
            return $diffLf;
        }

        /** @var list<string> */
        $built = [];
        $lines = explode("\n", $diffLf);
        $lastIndex = count($lines) - 1;

        foreach ($lines as $i => $line) {
            $isMeta = $line !== '' && (
                str_starts_with($line, 'diff --git ')
                || str_starts_with($line, '--- ')
                || str_starts_with($line, '+++ ')
                || str_starts_with($line, '@@ ')
                || str_starts_with($line, '\\')
            );

            $isPayload = !$isMeta
                && (
                    (($line[0] ?? '') === ' ' || ($line[0] ?? '') === '+' || ($line[0] ?? '') === '-')
                    && !str_starts_with($line, '--- ')
                    && !str_starts_with($line, '+++ ')
                );

            if ($line === '' && $i === $lastIndex) {
                $built[] = '';

                continue;
            }

            if ($isPayload) {
                $prefix = $line[0];
                $rest = substr($line, 1);
                $built[] = "{$prefix}{$rest}\r\n";

                continue;
            }

            $built[] = $line !== '' || $i < $lastIndex ? "{$line}\n" : '';
        }

        return implode('', $built);
    }

    /**
     * @return list<string>
     */
    private static function patchFailureHints(): array
    {
        return [
            'Paths in ---/+++ lines without a/subdir prefixes usually need strip 0 (-p0); omit strip to retry automatically.',
            'If editing on Windows with CRLF, the library retries with CRLF-sized hunk lines; still ensure context matches.',
            '`--ignore-whitespace` is tried as a fallback when strict context fails.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function gitStatus(array $parameters): array
    {
        $cwd = $parameters['working_directory'] ?? '';
        if (!is_string($cwd) || $cwd === '') {
            return ['error' => 'working_directory is required'];
        }

        $real = realpath($cwd);
        if ($real === false || !is_dir($real)) {
            return ['error' => 'working_directory is not a directory'];
        }

        $run = self::runProcess(['git', 'status', '--porcelain'], $real, null);

        return [
            'exit_code' => $run['exit_code'],
            'output' => $run['stdout'],
            'stderr' => trim($run['stderr']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function runPhpUnit(array $parameters): array
    {
        $cwd = $parameters['working_directory'] ?? '';
        if (!is_string($cwd) || $cwd === '') {
            return ['error' => 'working_directory is required'];
        }

        $real = realpath($cwd);
        if ($real === false || !is_dir($real)) {
            return ['error' => 'working_directory is not a directory'];
        }

        $phpunitPath = $parameters['phpunit_path'] ?? 'vendor/bin/phpunit';
        if (!is_string($phpunitPath) || $phpunitPath === '') {
            return ['error' => 'phpunit_path must be a non-empty string'];
        }

        $phpBin = PHP_BINARY;
        $resolvedPhpunit = self::isAbsolutePath($phpunitPath)
            ? $phpunitPath
            : $real . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $phpunitPath);

        if (!is_file($resolvedPhpunit)) {
            return ['error' => 'phpunit script not found at ' . $resolvedPhpunit];
        }

        $extra = $parameters['arguments'] ?? [];
        $args = ['php', $resolvedPhpunit];
        if (is_array($extra)) {
            foreach ($extra as $a) {
                if (is_string($a) && $a !== '') {
                    $args[] = $a;
                }
            }
        }

        $args[0] = $phpBin;

        $run = self::runProcess($args, $real, null);

        return [
            'exit_code' => $run['exit_code'],
            'stdout' => $run['stdout'],
            'stderr' => $run['stderr'],
        ];
    }

    private static function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }

        return strlen($path) >= 3 && ctype_alpha($path[0]) && $path[1] === ':' && ($path[2] === '\\' || $path[2] === '/');
    }

    /**
     * @param list<string> $command
     * @return array{stdout: string, stderr: string, exit_code: int}
     */
    private static function runProcess(array $command, string $cwd, ?string $stdin): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = @proc_open($command, $descriptors, $pipes, $cwd);
        if (!is_resource($proc)) {
            return ['stdout' => '', 'stderr' => 'failed to start process', 'exit_code' => -1];
        }

        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);

        $code = proc_close($proc);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exit_code' => $code];
    }
}
