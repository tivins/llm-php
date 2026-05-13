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
 */
class PredefinedTools
{
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
            'Search the web for summaries and instant answers via DuckDuckGo JSON API (not full page HTML). Use fetch_web_page to load a specific URL.',
            [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Search query.',
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
            'Fetch a document over HTTP GET only (http/https). Response body may be truncated to stay within max_bytes.',
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
            'Apply a unified diff using the patch program (must be installed and on PATH).',
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
                        'description' => 'Path strip count for patch -p (default 1).',
                        'default' => 1,
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
     * @return array<string, mixed>
     */
    public static function webSearch(array $parameters): array
    {
        $query = $parameters['query'] ?? '';
        if (!is_string($query) || $query === '') {
            return ['error' => 'query must be a non-empty string'];
        }

        $url = 'https://api.duckduckgo.com/?' . http_build_query([
            'q' => $query,
            'format' => 'json',
            'no_html' => '1',
            'skip_disambig' => '1',
        ]);

        $ch = curl_init($url);
        if ($ch === false) {
            return ['error' => 'could not initialize HTTP client'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'tivins/llm-php (PredefinedTools; +https://github.com/tivins/llm-php)',
        ]);

        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $body === '') {
            return ['error' => $err !== '' ? $err : 'empty response', 'http_status' => $code];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['error' => 'invalid JSON in response', 'http_status' => $code];
        }

        return [
            'abstract' => (string) ($decoded['Abstract'] ?? ''),
            'abstract_url' => (string) ($decoded['AbstractURL'] ?? ''),
            'related_topics' => self::flattenDdgTopics($decoded['RelatedTopics'] ?? []),
            'answer' => (string) ($decoded['Answer'] ?? ''),
            'http_status' => $code,
        ];
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
            CURLOPT_SSL_VERIFYPEER => true,
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
        ]);

        curl_exec($ch);
        $err = curl_error($ch);
        $errno = curl_errno($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $effective = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($body === '' && $errno !== 0) {
            return ['error' => $err !== '' ? $err : 'request failed', 'curl_errno' => $errno];
        }

        return [
            'url' => $effective !== '' ? $effective : $url,
            'http_status' => $code,
            'content_type' => $ctype,
            'truncated' => $truncated,
            'body' => $body,
        ];
    }

    /**
     * @param mixed $topics
     * @return list<string>
     */
    private static function flattenDdgTopics(mixed $topics, int $limit = 15): array
    {
        if (!is_array($topics)) {
            return [];
        }

        $out = [];
        foreach ($topics as $t) {
            if (count($out) >= $limit) {
                break;
            }
            if (is_string($t)) {
                $out[] = $t;
                continue;
            }
            if (is_array($t) && isset($t['Text']) && is_string($t['Text'])) {
                $out[] = $t['Text'];
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function applyDiff(array $parameters): array
    {
        $diff = $parameters['diff'] ?? '';
        $cwd = $parameters['working_directory'] ?? '';
        if (!is_string($diff) || $diff === '' || !is_string($cwd) || $cwd === '') {
            return ['error' => 'diff and working_directory are required'];
        }

        $real = realpath($cwd);
        if ($real === false || !is_dir($real)) {
            return ['error' => 'working_directory is not a directory'];
        }

        $strip = isset($parameters['strip']) ? (int) $parameters['strip'] : 1;
        $strip = max(0, min(10, $strip));

        $run = self::runProcess(['patch', '-p' . $strip, '--batch', '--forward'], $real, $diff);
        if ($run['exit_code'] === 0) {
            return ['ok' => true, 'stdout' => trim($run['stdout'])];
        }

        return [
            'ok' => false,
            'exit_code' => $run['exit_code'],
            'stdout' => trim($run['stdout']),
            'stderr' => trim($run['stderr']),
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
