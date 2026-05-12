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

    // grep : find text in files and return the lines that contain the text

    // web search : search the web for information

    // apply diff : apply a diff to a file

    // git status : get the status of the git repository

    // run unit tests (phpunit)

    // -----

    public static function all(): array
    {
        return [
            'read_file' => self::getReadFileTool(),
            'write_file' => self::getWriteFileTool(),
            'get_date_time' => self::getDateTimeTool(),
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
            default => json_encode(['error' => 'internal tool error'], JSON_THROW_ON_ERROR),
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
}
