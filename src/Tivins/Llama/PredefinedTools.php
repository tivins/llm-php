<?php

declare(strict_types=1);

namespace Tivins\Llama;

/**
 * Predefined tools for the Llama API.
 */
// `parameters` must be a JSON Schema object (`type`, `properties`, `required`, …). A shorthand like
// `['file_path' => 'string']` is not valid schema; servers and models then ignore property names and may emit
// `path`, `filename`, etc. See `examples/chat_tools.php` for the same pattern.
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

    public static function getExecuteTools(): array
    {
        return [
            'read_file' => [self::class, 'readFile'],
            'write_file' => [self::class, 'writeFile'],
            'get_date_time' => [self::class, 'getDateTime'],
        ];
    }

    public static function readFile(array $parameters): string
    {
        return file_get_contents($parameters['file_path']);
    }

    public static function writeFile(array $parameters): void
    {
        file_put_contents($parameters['file_path'], $parameters['content']);
    }

    public static function getDateTime(): string
    {
        return date('Y-m-d H:i:s e');
    }
}