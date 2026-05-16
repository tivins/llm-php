**Language:** English

# llm-php (`tivins/llm-php`)

**Version:** 1.20.4 (see [`composer.json`](composer.json); release history in [`CHANGELOG.md`](CHANGELOG.md)).

PHP client library for an **OpenAI-compatible** HTTP API—typically **[llama.cpp `llama-server`](https://github.com/ggml-org/llama.cpp/blob/master/tools/server/README.md)**—covering `POST /v1/chat/completions` (non-stream and **SSE** stream), plus **`/health`**, **`/tokenize`**, and model discovery via **`GET /v1/models`**.

**In this README:** [Why not only `chat()`](#api-surface-chat-vs-chatcompletions-vs-chatstream) · [Module map](#module-map-srctivinsllama) · [Examples](#examples) · [Environment variables](#environment-variables) · [Tests](#tests) · [Conversation logging and modern message fields](#conversation-logging-and-modern-message-fields) · [JSONL audit logs](#jsonl-audit-logs-turnjsonllogger--turnrecord) · [Console output](#console-output-humanturnrenderer--humanturnstreamdisplay) · [Pitfalls](#pitfalls-and-limits) · [Install](#installation)

---

## Installation

### Package

```shell
composer require tivins/llm-php
```

**Requires:** `ext-curl`.

### llama.cpp server

- Install: [llama.cpp install docs](https://github.com/ggml-org/llama.cpp/blob/master/docs/install.md)
- Server API: [server README](https://github.com/ggml-org/llama.cpp/blob/master/tools/server/README.md)

### Models

Use a **GGUF** checkpoint sized for your GPU/RAM; exact limits depend on quantization and context. The library does not download models—it only talks to a running server.

If you have a GPU with 8–16GB of VRAM, you can try these models:

- [https://huggingface.co/bartowski/google_gemma-4-E4B-it-GGUF](https://huggingface.co/bartowski/google_gemma-4-E4B-it-GGUF) (Q5_K_M or Q6_K)
- [https://huggingface.co/bartowski/gemma-2-9b-it-GGUF](https://huggingface.co/bartowski/gemma-2-9b-it-GGUF) (Q4_K_M)
- [https://huggingface.co/bartowski/Qwen2.5-7B-Instruct-GGUF](https://huggingface.co/bartowski/Qwen2.5-7B-Instruct-GGUF) (Q4_K_M)

---

## Quick start

**Prerequisite:** start **[`llama-server`](https://github.com/ggml-org/llama.cpp/blob/master/tools/server/README.md)** and keep it running so the PHP client can reach its OpenAI-compatible HTTP API. Example (adjust the GGUF path and port):

```bash
llama-server -m ./model.gguf --port 8080 --no-webui -lv 0
```

The snippet below assumes the default listen address **`http://127.0.0.1:8080`**.

From the repository root after `composer install`:

```php
use Tivins\Llama\BehaviorPrompts;
use Tivins\Llama\Conversation;
use Tivins\Llama\Lama;
use Tivins\Llama\Message;
use Tivins\Llama\Role;

require __DIR__ . '/vendor/autoload.php';

$lama = Lama::fromServerUrl('http://127.0.0.1:8080');
$conversation = new Conversation();
$conversation->addMessage(new Message(Role::System, BehaviorPrompts::HELPFUL));
$conversation->addMessage(new Message(Role::User, 'Hello.'));
$text = $lama->chat($conversation);
```

`Lama::fromServerUrl()` picks the **first** model id from `/v1/models`. Check `$lama->getHealth()` returns `'ok'` before relying on the server.

Sampling and generation knobs (`temperature`, `top_p`, `max_tokens`, penalties, `seed`, `stop`, `n`, **`tools`**, **`tool_choice`**) go through **`Tivins\Llama\ChatCompletionOptions`**: only properties you set are merged into the JSON body; omitted keys leave server defaults.

---

## API surface: `chat()` vs `chatCompletions()` vs `chatStream()`

| Method | Input → output | Preserved | Lost or narrowed |
|--------|----------------|-----------|------------------|
| **`Tivins\Llama\Lama::chat()`** | `Conversation` + optional `ChatCompletionOptions` → **`string`** | Final assistant **`content`** from `choices[0].message` (empty string if missing) | **`tool_calls`**, native **`reasoning_content`**, **`usage`**, additional choices, full wire JSON |
| **`Tivins\Llama\Lama::chatCompletions()`** | Same → **decoded JSON `array`** (`choices`, `usage`, …) | Everything the **server** returns in that response body | Nothing by the library—you inspect the array |
| **`Tivins\Llama\Lama::chatStream()`** | Same + stream callbacks + optional **`Tivins\Llama\SsePayloadCapture`** → **`Tivins\Llama\StreamResult`** | Aggregated **`content`**, **`reasoning_content`** (when streamed), reconstructed **`tool_calls`**, **`finish_reason`**; **`usage`**, **`model`**, **`id`** when present on **stream chunks** (otherwise `null`) | Per-chunk raw JSON unless you capture SSE payloads; shape of **`usage`** is backend-specific |

**Fidelity path:** use **`chatCompletions()`** and/or **`chatStream()`**, then map results into your own structures or into **`Tivins\Llama\Dto\NormalizedTurnOutcome`** (`fromChatCompletionArray()` / `fromStreamResult()`) for a single aggregate shape across modes.

**Shortcut:** `chat()` is documented in code as a thin wrapper around `chatCompletions()`; it is fine for plain text, not for tools or native reasoning traces.

---

## Module map (`src/Tivins/Llama/`)

### Client

- **`Tivins\Llama\Lama`** — HTTP client: `fromServerUrl()`, `getHealth()` / `getHealthRaw()`, `tokenize()`, `chat()`, `chatCompletions()`, `chatStream()`.

### Conversation model

- **`Tivins\Llama\Conversation`** — ordered `Message` list; `toChatCompletionMessages()` builds OpenAI-style `messages` (assistant `tool_calls`, `tool` role + `tool_call_id`, optional assistant **`reasoning_content`**).
- **`Tivins\Llama\Message`** — `Role`, `content`, optional `toolCallId` / `name` for `Role::Tool`, optional `toolCalls` (assistant), optional **`reasoningContent`** (native reasoning); `normalizeReasoningContent()` treats `null` and `''` as absent for JSON.
- **`Tivins\Llama\Role`** — `system`, `user`, `assistant`, `tool`.

### Request options

- **`Tivins\Llama\ChatCompletionOptions`** — OpenAI-shaped optional fields merged into the chat-completions body (see class docblock for semantics and local-server caveats).

### Tools

- **`Tivins\Llama\ChatFunctionTool`** — build one `tools[]` entry (`toToolArray()`, `toToolArrays()`).
- **`Tivins\Llama\ToolCallingLoop`** — multi-round loop over **`chatCompletions()`**: executes tools, appends `Role::Tool` messages, copies **`reasoning_content`** when present; final assistant turn has no `tool_calls` when idle; throws if max rounds exhausted with pending tools.
- **`Tivins\Llama\StreamingToolCallingLoop`** — same orchestration over **`chatStream()`**; optional **`onAssistantStreamRound(StreamResult, RawStreamTrace, int)`** for logging (SSE capture when callback is used).
- **`Tivins\Llama\PredefinedTools`** — ready-made tools (search, fetch, filesystem helpers, `apply_diff`, git helpers, etc.) with executors suited to examples; see **`examples/`** and class docblock (includes TLS-related environment variables).

### DTOs and audit

- **`Tivins\Llama\Dto\TurnRecord`** — one logical turn for JSONL (`forCompletion` / `forStream`, `toLogArray()`).
- **`Tivins\Llama\Dto\RawChatCompletionResponse`** — wraps non-stream completion JSON.
- **`Tivins\Llama\Dto\RawStreamTrace`** — `events` (list of **`StreamEvent`**) plus optional **`rawDataLines`** (verbatim SSE JSON strings).
- **`Tivins\Llama\Dto\StreamEvent`** / **`Tivins\Llama\Dto\StreamEventKind`** — structured stream replay types (fine-grained event lists may be empty depending on capture path).
- **`Tivins\Llama\Dto\NormalizedTurnOutcome`** — normalized assistant fields from completion JSON or **`StreamResult`**.
- **`Tivins\Llama\SsePayloadCapture`** — mutable bag of SSE JSON payload strings for **`RawStreamTrace::$rawDataLines`**.
- **`Tivins\Llama\TurnJsonlLogger`** — append one JSON line per `TurnRecord`.

### Streaming aggregation

- **`Tivins\Llama\ChatStreamAccumulator`** — parses `data: {...}` SSE lines into **`StreamResult`** (shared by **`Lama::chatStream()`** and tests/fixtures).
- **`Tivins\Llama\StreamResult`** — `content`, `finishReason`, `toolCalls`, `reasoningContent`, optional `usage`, `model`, `id`.

### Console rendering

- **`Tivins\Llama\RenderOptions`** — ANSI, stdout/stderr injectable, reasoning channel.
- **`Tivins\Llama\HumanTurnRenderer`** — render **`NormalizedTurnOutcome`**, **`TurnRecord`**, or raw completion payload (`renderCompletionPayload()`).
- **`Tivins\Llama\HumanTurnStreamDisplay`** — stream-friendly callbacks aligned with **`Lama::chatStream()`** / **`StreamingToolCallingLoop`**.

### Higher-level helpers (optional)

- **`Tivins\Llama\ThinkingChat`** / **`ThinkingPrompts`** / **`ThinkingTurnResult`** — **two HTTP rounds** (reasoning prompt then answer); **not** the same as a single completion’s native **`reasoning_content`** (see class docblock).
- **`Tivins\Llama\BehaviorPrompts`** — ready-made system prompt strings.
- **`Tivins\Llama\Translator`** — translation helper built on **`Lama::chat()`** with optional FIFO cache.

---

## Examples

Location: **`examples/`**. Scripts use:

```text
require __DIR__ . '/../vendor/autoload.php';
```

Run from the **repository root** (so `vendor/` resolves), with **llama-server** listening where the script expects (many examples use `http://127.0.0.1:8080`):

```shell
composer install
php examples/chat.php
php examples/completions.php
php examples/tokenize.php
php examples/tools_chain.php
php examples/stream_tools_chain.php
php examples/web_lookup_chain.php
php examples/stream_web_lookup_chain.php
php examples/workspace_tools_demo.php
```

Additional demos include `chat_tools.php`, `mediation.php`, `moderation.php`, `exemples.php`, etc. Prefer reading each file’s header comment for prerequisites (e.g. `patch` on PATH for `workspace_tools_demo.php`).

Shared helpers: **`examples/_helpers.php`** (`print_output()`, JSONL helpers, render env parsing). Optional defaults: **`examples/.env`** (loaded without overriding variables already in the process environment).

---

## Environment variables

Values are read via `getenv()` / `putenv()` in library or example code as documented below. **Do not enable logging** if completions could contain secrets.

### Examples / console (`examples/_helpers.php`, `examples/.env`)

| Variable | Effect |
|----------|--------|
| **`TIVINS_LLAMA_CONVERSATION_LOG`** | Path to a JSONL file; **`TurnJsonlLogger`** appends one line per logical turn when examples wire logging (`TurnRecord::toLogArray()`). Use **`{session}`** in the path for a per-process segment (new file each CLI run). |
| **`TIVINS_LLAMA_NO_ANSI`** | Truthy (`1`, `true`, `yes`, `on`): disable ANSI in **`HumanTurnRenderer`** / **`HumanTurnStreamDisplay`**. |
| **`TIVINS_LLAMA_REASONING_STDOUT`** | Truthy: print reasoning on stdout instead of stderr. |
| **`TIVINS_LLAMA_COMPLETION_DUMP_RAW`** | Truthy: **`print_output()`** uses legacy verbose debug instead of **`HumanTurnRenderer`**. |

`example_load_examples_env_file()` reads **`examples/.env`** only for keys **not** already set in the environment.

### HTTP/TLS for tool traffic (`PredefinedTools`, e.g. `web_search` / `fetch_web_page`)

| Variable | Effect |
|----------|--------|
| **`TIVINS_LLAMA_CURL_CAINFO`** | Path to a PEM CA bundle. |
| **`TIVINS_LLAMA_CURL_WINDOWS_NATIVE_CA`** | `1` prefers Windows certificate store even when **`CAINFO`** is set; `0` disables native CA behaviour (see **`PredefinedTools`** docblock). |
| **`TIVINS_LLAMA_HTTP_SSL_VERIFY`** | `0` / `false` / `no` / `off` disables TLS verification (insecure; dev only). |

Programmatic override: **`PredefinedTools::setHttpSslVerifyPeer()`** (documented on the class).

---

## Tests

Convention: each file is a standalone script run with PHP:

```shell
php tests/<name>_test.php
```

**Automated coverage (no live server for most):**

- **`tests/chat_completion_options_test.php`** — `ChatCompletionOptions` and request body assembly (includes reasoning serialization behaviour).
- **`tests/normalized_turn_outcome_test.php`** — `ChatStreamAccumulator` / stream aggregation using **`tests/fixtures/sse_chat_stream_enriched_fixture.sse.txt`** (`content`, `reasoning_content`, `tool_calls`, `finish_reason`, `usage`).
- **`tests/human_turn_renderer_test.php`** — `HumanTurnRenderer` and stream display with in-memory streams.
- **`tests/examples_env_loader_test.php`** — `examples/.env` loading respects an existing **`TIVINS_LLAMA_CONVERSATION_LOG`**.
- **`tests/conversation_log_path_session_test.php`** — `{session}` placeholder in **`TIVINS_LLAMA_CONVERSATION_LOG`** resolves once per process.
- **`tests/turn_jsonl_logger_test.php`** — JSONL logger.
- **`tests/turn_record_test.php`** — `TurnRecord` / DTO golden shapes.
- **`tests/tool_calling_loop_test.php`** — non-stream and streaming tool loops (including callbacks / exhaustion cases).
- **`tests/predefined_tools_test.php`** — bundled tool behaviour.

**Interactive / diagnostic:**

- **`tests/stream_probe.php`** — live server: classifies incremental vs cumulative `content` deltas (complements fixture tests).

Requires `vendor/autoload.php` (`composer install`).

---

## Conversation logging and modern message fields

Use this overview to wire **audit logs**, **replay**, and **native reasoning** without re-reading every subsection; details are in the linked sections.

### `Message` and constructor compatibility

- Assistant messages support optional **`reasoningContent`** (wire key `reasoning_content`). New code should pass it with a **named argument** (`reasoningContent: '…'`) so it does not collide with **`$toolCalls`**.
- Existing calls that only pass **`Role`**, **`content`**, and tool fields stay compatible. If you previously used **five positional** arguments for an assistant message, recall the signature is **`(role, content, toolCallId, name, toolCalls, reasoningContent)`** — add reasoning via the **last** named parameter rather than shifting arguments.

### Tool-calling loops (behavior change)

- Since **1.14.0**, **`ToolCallingLoop`** and **`StreamingToolCallingLoop`** append the **final** assistant turn (no `tool_calls`) when the model finishes, and throw if **`maxRounds`** is exhausted while tools are still pending. See [`CHANGELOG.md`](CHANGELOG.md) (1.14.0).

### JSONL audit and replay

- Enable **`TIVINS_LLAMA_CONVERSATION_LOG`** in examples ([Environment variables](#environment-variables)); each line is JSON from **`TurnRecord::toLogArray()`** (`raw_completion` or `raw_stream` + **`stream_result`**, optional **`request_messages`** for the prompt snapshot).
- Reconstruct records with **`TurnRecord::fromLogArray()`**; terminal replay: **`examples/replay_turn_jsonl.php`**. Full field list: [JSONL audit logs](#jsonl-audit-logs-turnjsonllogger--turnrecord).

### Normalized view and console

- **`NormalizedTurnOutcome`** maps both non-stream completions and **`StreamResult`** into one shape ([API surface](#api-surface-chat-vs-chatcompletions-vs-chatstream)).
- **`HumanTurnRenderer`** / **`HumanTurnStreamDisplay`** cover human-readable output ([Console output](#console-output-humanturnrenderer--humanturnstreamdisplay)).

Contributor-facing implementation history for these features lives in [`CHANGELOG.md`](CHANGELOG.md) (releases **1.14.0** through **1.20.x**) and in the sections above.

---

## JSONL audit logs (`TurnJsonlLogger` / `TurnRecord`)

- **`TurnJsonlLogger`** writes **one JSON object per line** from **`TurnRecord::toLogArray()`**.
- **Non-stream:** `TurnRecord::forCompletion()` stores the full completion JSON under `raw_completion`.
- **Stream:** `TurnRecord::forStream()` includes **`StreamResult`** fields and **`RawStreamTrace`**; when SSE capture is used, **`raw_data_lines`** holds verbatim SSE JSON strings; structured **`events`** may be empty depending on the capture path.
- **Request context:** when examples pass **`request_messages`** (same shape as **`Conversation::toChatCompletionMessages()`**), logs include the prompt snapshot for that request; **`examples/replay_turn_jsonl.php`** prints it before options and assistant output.

Treat log files as **sensitive** if they can contain user data or downstream secrets.

---

## Console output (`HumanTurnRenderer` / `HumanTurnStreamDisplay`)

- **`HumanTurnRenderer`** prints usage (if present), model/id, finish reason, reasoning block, content, and tool calls for **`NormalizedTurnOutcome`** or **`TurnRecord`** replay; **`renderCompletionPayload()`** adapts a raw **`chatCompletions()`** array.
- **`HumanTurnStreamDisplay`** separates streamed **content** (stdout by default), **reasoning** (stderr by default), and tool fragments / summaries (stderr), consistent with **`examples/stream_*.php`**.
- On Windows, use a modern terminal (e.g. Windows Terminal) for ANSI; or set **`TIVINS_LLAMA_NO_ANSI`**.

---

## Pitfalls and limits

- **`Lama::chat()`** discards everything except first-choice **`content`**—avoid it for tool calling or native **`reasoning_content`**.
- **Streaming `usage`:** many backends omit **`usage`** on SSE chunks; **`StreamResult::$usage`** stays `null` unless the server sends a usable **`usage`** object on a parsed chunk (the library keeps the **last** such object).
- **Wire JSON shapes** vary by server version; rely on this library’s aggregation helpers and tests for supported fields, or inspect raw payloads / logs.
- **JSONL and captures** can persist full prompts and completions—**no secrets** in shared logs.
- **`ChatCompletionOptions`** passes through keys the **server** may ignore or reject—check your backend’s supported subset.

---

## License

MIT — see [`composer.json`](composer.json).
