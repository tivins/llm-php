<?php

declare(strict_types=1);

/**
 * Démo : `read_file`, `write_file`, `grep`, `apply_diff` dans un répertoire de travail borné (sandbox).
 *
 * Le 1er argument est le **workspace** : tous les chemins passés aux outils doivent désigner des fichiers ou
 * dossiers qui restent sous ce répertoire (après résolution). Les chemins peuvent être relatifs au workspace.
 *
 * Prérequis :
 * - Serveur chat completions sur {@see Lama::fromServerUrl()}.
 * - **`patch` sur le PATH** (requis pour `apply_diff`). Sous Windows hors Git Bash/WSL, `patch` est souvent absent :
 *   le script quitte avec un message invitant à utiliser Git Bash, WSL ou à ajouter `patch` au PATH.
 *
 * Usage : `php examples/workspace_tools_demo.php /chemin/vers/workspace`
 */

use Tivins\Llama\BehaviorPrompts;
use Tivins\Llama\ChatCompletionOptions;
use Tivins\Llama\ChatFunctionTool;
use Tivins\Llama\Conversation;
use Tivins\Llama\Lama;
use Tivins\Llama\Message;
use Tivins\Llama\PredefinedTools;
use Tivins\Llama\Role;
use Tivins\Llama\ToolCallingLoop;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_helpers.php';

function patch_cli_available(): bool
{
    $unusedOutput = [];
    $exitCode = 1;
    @exec('patch --version 2>&1', $unusedOutput, $exitCode);

    return $exitCode === 0;
}

function path_is_absolute(string $path): bool
{
    if ($path === '') {
        return false;
    }

    if ($path[0] === '/' || $path[0] === '\\') {
        return true;
    }

    if (str_starts_with($path, '\\\\')) {
        return true;
    }

    return strlen($path) >= 3
        && ctype_alpha($path[0])
        && $path[1] === ':'
        && ($path[2] === '\\' || $path[2] === '/');
}

function path_is_inside_workspace(string $workspaceReal, string $resolvedPath): bool
{
    $ws = realpath($workspaceReal);
    if ($ws === false) {
        return false;
    }

    $candidate = realpath($resolvedPath);
    if ($candidate === false) {
        return false;
    }

    $wsNorm = strtolower(str_replace('\\', '/', $ws));
    $cNorm = strtolower(str_replace('\\', '/', $candidate));

    return $cNorm === $wsNorm || str_starts_with($cNorm, $wsNorm . '/');
}

/**
 * @return non-empty-string|false
 */
function resolve_workspace_path(string $workspaceReal, string $userPath, bool $mustExist): string|false
{
    $userPath = trim($userPath);
    if ($userPath === '') {
        return false;
    }

    if ($userPath === '.' || $userPath === './' || $userPath === '.\\') {
        $candidate = $workspaceReal;
    } elseif (!path_is_absolute($userPath)) {
        $rel = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $userPath);
        $parts = explode(DIRECTORY_SEPARATOR, $rel);
        $acc = $workspaceReal;
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                return false;
            }
            $acc .= DIRECTORY_SEPARATOR . $part;
        }
        $candidate = $acc;
    } else {
        $candidate = $userPath;
    }

    if (file_exists($candidate)) {
        $resolved = realpath($candidate);
        if ($resolved === false || !path_is_inside_workspace($workspaceReal, $resolved)) {
            return false;
        }

        return $resolved;
    }

    if ($mustExist) {
        return false;
    }

    $leaf = basename($candidate);
    if ($leaf === '.' || $leaf === '..' || str_contains($leaf, '..')) {
        return false;
    }

    $parent = dirname($candidate);
    $parentReal = realpath($parent);
    if ($parentReal === false || !path_is_inside_workspace($workspaceReal, $parentReal)) {
        return false;
    }

    return $parentReal . DIRECTORY_SEPARATOR . $leaf;
}

/**
 * @return callable(string, array<string, mixed>): string
 */
function make_workspace_tool_executor(string $workspaceReal): callable
{
    return static function (string $name, array $parameters) use ($workspaceReal): string {
        try {
            return match ($name) {
                'read_file' => (static function () use ($workspaceReal, $parameters): string {
                    $raw = $parameters['file_path'] ?? '';
                    $path = is_string($raw) ? $raw : '';
                    $resolved = resolve_workspace_path($workspaceReal, $path, true);
                    if ($resolved === false || !is_file($resolved)) {
                        return json_encode(['error' => 'file_path must be an existing file inside the workspace'], JSON_THROW_ON_ERROR);
                    }

                    return PredefinedTools::runTool('read_file', ['file_path' => $resolved]);
                })(),
                'write_file' => (static function () use ($workspaceReal, $parameters): string {
                    $raw = $parameters['file_path'] ?? '';
                    $path = is_string($raw) ? $raw : '';
                    $resolved = resolve_workspace_path($workspaceReal, $path, false);
                    if ($resolved === false) {
                        return json_encode(['error' => 'file_path must stay inside the workspace (parent directory must exist)'], JSON_THROW_ON_ERROR);
                    }

                    return PredefinedTools::runTool('write_file', [
                        'file_path' => $resolved,
                        'content' => is_string($parameters['content'] ?? null) ? $parameters['content'] : '',
                    ]);
                })(),
                'grep' => (static function () use ($workspaceReal, $parameters): string {
                    $raw = $parameters['path'] ?? '';
                    $path = is_string($raw) ? $raw : '';
                    $resolved = resolve_workspace_path($workspaceReal, $path, true);
                    if ($resolved === false || (!is_file($resolved) && !is_dir($resolved))) {
                        return json_encode(['error' => 'path must be an existing file or directory inside the workspace'], JSON_THROW_ON_ERROR);
                    }

                    $forward = $parameters;
                    $forward['path'] = $resolved;

                    return PredefinedTools::runTool('grep', $forward);
                })(),
                'apply_diff' => (static function () use ($workspaceReal, $parameters): string {
                    $raw = $parameters['working_directory'] ?? '';
                    $wd = is_string($raw) ? $raw : '';
                    $resolved = resolve_workspace_path($workspaceReal, $wd, true);
                    if ($resolved === false || !is_dir($resolved)) {
                        return json_encode(['error' => 'working_directory must be an existing directory inside the workspace'], JSON_THROW_ON_ERROR);
                    }

                    $forward = $parameters;
                    $forward['working_directory'] = $resolved;

                    return PredefinedTools::runTool('apply_diff', $forward);
                })(),
                default => json_encode(['error' => 'tool not exposed in this demo'], JSON_THROW_ON_ERROR),
            };
        } catch (\JsonException $e) {
            return json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR);
        }
    };
}

// --- bootstrap ---

if ($argc < 2 || ($argv[1] ?? '') === '-h' || ($argv[1] ?? '') === '--help') {
    fwrite(STDERR, "Usage: php examples/workspace_tools_demo.php <workspace_directory>\n");
    exit($argc < 2 ? 1 : 0);
}

$workspaceArg = $argv[1];
if (!is_string($workspaceArg) || $workspaceArg === '') {
    fwrite(STDERR, "Invalid workspace path.\n");
    exit(1);
}

if (!is_dir($workspaceArg)) {
    fwrite(STDERR, "Workspace is not a directory: {$workspaceArg}\n");
    exit(1);
}

$workspaceReal = realpath($workspaceArg);
if ($workspaceReal === false) {
    fwrite(STDERR, "Could not resolve workspace path.\n");
    exit(1);
}

if (!patch_cli_available()) {
    fwrite(STDERR, <<<'ERR'
The `patch` program was not found or does not run successfully on your PATH.
`apply_diff` requires GNU patch (or compatible).

On Windows, open this demo from Git Bash, use WSL, or install patch and add it to PATH.

ERR);
    exit(1);
}

$lama = Lama::fromServerUrl('http://127.0.0.1:8080');

$tools = [
    PredefinedTools::getReadFileTool(),
    PredefinedTools::getWriteFileTool(),
    PredefinedTools::getGrepTool(),
    PredefinedTools::getApplyDiffTool(),
];

$toolSchemas = ChatFunctionTool::toToolArrays($tools);

$workspaceForwardSlashes = str_replace('\\', '/', $workspaceReal);

$systemPrompt = BehaviorPrompts::HELPFUL . <<<TEXT


You are editing files only inside this workspace root (absolute path): {$workspaceForwardSlashes}
- Prefer relative paths from that root for tool arguments (e.g. `src/app.py`), unless you need an absolute path.
- Do not attempt to read or write outside this workspace; such tool calls will fail.

When asked to change existing code, prefer `apply_diff` with a unified diff over rewriting whole files with `write_file`.
You may omit `strip` on `apply_diff` so the client can auto-pick a sensible `-p` and retry; use `--- a/file` / `+++ b/file` when possible.
Use `grep` to locate symbols or strings before editing when multiple files exist or the exact location is unclear.
Use `read_file` to verify file contents when needed.

Respond in the same language as the user's message unless they ask otherwise.
TEXT;

$executeTool = make_workspace_tool_executor($workspaceReal);

$options = new ChatCompletionOptions(tools: $toolSchemas, tool_choice: 'auto');

$conversation = new Conversation();
$conversation->addMessage(new Message(Role::System, $systemPrompt));

$runTurn = static function (string $userMessage) use (
    $lama,
    $conversation,
    $options,
    $executeTool,
): void {
    $conversation->addMessage(new Message(Role::User, $userMessage));

    $output = $lama->chatCompletions($conversation, $options);
    if ($output === null || !isset($output['choices'][0])) {
        fwrite(STDERR, "No completion response (null or missing choices).\n");
        exit(1);
    }

    print_output($output);

    try {
        $output = (new ToolCallingLoop($lama))->runUntilIdle(
            $conversation,
            $options,
            $output,
            $executeTool,
            16,
            static function (string $name, array $args): void {
                fwrite(STDERR, '[tool] ' . $name . ' ' . json_encode($args, JSON_UNESCAPED_UNICODE) . "\n");
            },
            print_output(...),
        );
    } catch (\RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }

    $finalContent = $output['choices'][0]['message']['content'] ?? '';
    if (is_string($finalContent) && $finalContent !== '') {
        echo "\n--- Assistant reply ---\n" . $finalContent . "\n---\n";
    }
};

try {
    $runTurn(
        <<<USER
Dans le workspace, crée un petit programme Python `hello_demo.py` qui définit une fonction `greet(name)` retournant
« Hello, {name}! » et un `if __name__ == "__main__":` qui affiche le résultat pour `name="world"`.
Ensuite :
1. Utilise `read_file` pour confirmer le contenu de `hello_demo.py`.
2. Utilise `grep` avec le chemin `.` (répertoire racine du workspace) et un motif littéral pour trouver la ligne contenant `def greet`.
Explique brièvement ce que tu as fait après les appels d’outils.
USER
    );

    $runTurn(
        <<<USER
Toujours dans le même workspace : modifie `hello_demo.py` pour que la salutation soit « Hi, {name}! » au lieu de « Hello »,
**uniquement** via l’outil `apply_diff` (pas `write_file`). Passe `working_directory` comme le répertoire racine du workspace
(relève `.` ou un chemin équivalent). Après le patch, utilise `read_file` pour montrer que le fichier a bien été mis à jour.

Vérifie aussi explicitement, d’après la réponse JSON renvoyée par `apply_diff`, que les champs `warnings` (tableau, souvent vide) et
`stderr` (souvent une chaîne vide) sont présents ; résume leur contenu. Si `warnings` n’est pas vide, précise qu’il s’agit
d’avertissements non bloquants distincts du succès (`ok`).
USER
    );
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
