# Changelog

## 1.15.0 — 2026-05-15

- Feature: `Message` accepts optional native assistant `reasoning_content` (`Message::$reasoningContent`); `Message::normalizeReasoningContent()` treats `null` and `''` as absent for JSON output.
- Feature: `Conversation::toChatCompletionMessages()` includes `reasoning_content` only on assistant messages when set (including tool-call rounds).
- Feature: `ToolCallingLoop` and `StreamingToolCallingLoop` copy reasoning from `choices[0].message.reasoning_content` / `StreamResult::$reasoningContent` into conversation history.
- Docs: `ThinkingChat` class doc clarifies two-phase prompting vs native `reasoning_content`.
- Tests: `tests/chat_completion_options_test.php` covers reasoning serialization and empty-string normalization.

## 1.14.0 — 2026-05-15

- **Behavior change:** `ToolCallingLoop::runUntilIdle()` appends the final assistant turn (without `tool_calls`) to `Conversation` so the stored thread matches replay to the API. If `$maxRounds` is exhausted while the latest completion still contains non-empty `tool_calls`, a `RuntimeException` is thrown (no fabricated reply).
- **Behavior change:** `StreamingToolCallingLoop::runUntilIdle()` mirrors the above: always appends the final `StreamResult` as a plain assistant message when no tool calls remain; otherwise throws when `$maxRounds` is exhausted with pending tools.
- Tests: `tests/tool_calling_loop_test.php` extended (non-stream + streaming mocks, exhaustion case).

## 1.13.0 — 2026-05-15

- Feature: logging / audit DTOs under `Tivins\Llama\Dto` — `StreamEventKind`, `StreamEvent`, `RawStreamTrace`, `RawChatCompletionResponse`, and `TurnRecord` (`forCompletion` / `forStream`, `toLogArray()` JSON-safe). Golden fixture test in `tests/turn_record_test.php`.

## 1.12.2 — 2026-05-15

- Feature: `ChatFunctionTool::toToolArrays(iterable)` builds the OpenAI `tools` payload from several tools (examples updated).

## 1.12.1 — 2026-05-15

- Fix: `apply_diff` appends a final newline to the unified diff before invoking `patch`, avoiding spurious “ends in middle of line” warnings on some GNU patch builds.
- Success JSON: always includes **`stderr`** (cleaned) and **`warnings`** — known benign `patch` diagnostic lines are parsed out of stdout/stderr and listed in `warnings`, leaving informational stdout (e.g. `patching file …`) intact.
- Docs: `examples/workspace_tools_demo.php` — second tour asks the model to confirm `warnings` / `stderr` in the tool response.

## 1.12.0 — 2026-05-15

- Feature: `apply_diff` — normalise les fins de ligne du diff en entrée, devine une liste de niveaux `-p` (chemins type `a/b` vs noms simples), enchaîne des tentatives (`strip` peut être omis ; si fourni, ce niveau est essayé en premier puis des repli). Essaie ensuite `--ignore-whitespace` ; si une cible sur disque est en CRLF, réécrit les lignes de hunk avec `\r` avant d’autres essais.
- Succès : ajout optionnel dans la charge utile JSON de **`strip_used`** et **`strategy`**. Échecs : **`hints`** (conseils côté appelant).
- Tests: couverture `-p` auto sur diff sans préfixe `a/`/`b/`, et cible fichier CRLF.
- Docs: `examples/workspace_tools_demo.php` — rappel d’omettre `strip` quand c’est pertinent.

## 1.11.1 — 2026-05-15

- Docs: `examples/workspace_tools_demo.php` — démo `read_file` / `write_file` / `grep` / `apply_diff` avec sandbox sur un répertoire passé en argument ; vérifie la présence de `patch` sur le PATH (Windows : message orientant vers Git Bash/WSL).

## 1.11.0 — 2026-05-14

- Feature: `fetch_web_page` returns **plain visible text by default** for HTML/XHTML (scripts/styles/noscript/template stripped, entities decoded, whitespace normalized); tool arg **`raw_html`** (default `false`) restores raw response bytes. Responses include boolean **`text_extracted`** indicating whether plaintext conversion ran.

## 1.10.1 — 2026-05-14

- Docs: `examples/stream_web_lookup_chain.php` — même scénario que `examples/web_lookup_chain.php` avec `StreamingToolCallingLoop` et flux SSE (`chatStream()`).

## 1.10.0 — 2026-05-14

- Feature: `Lama::chatStream()` streams `choices[0].delta.reasoning_content` into `StreamResult::$reasoningContent`; optional `$onReasoningDelta(string $fragment)`. If `delta` omits `reasoning_content`, uses `choices[0].message.reasoning_content` for that chunk (avoids appending message when delta already declares the field).
- Feature: `StreamingToolCallingLoop::runUntilIdle()` accepts optional `$onReasoningDelta` forwarded to `chatStream()`.

## 1.9.0 — 2026-05-14

- Feature: `StreamResult` value object returned by `Lama::chatStream()` — carries accumulated `content`, `finishReason`, and a reconstructed OpenAI-style `toolCalls` array.
- Feature: `Lama::chatStream()` now accumulates `delta.tool_calls` fragments by index across SSE events, captures `finish_reason`, and returns a `StreamResult`; optional `$onToolCallChunk(int $index, string $fragment)` callback delivers argument fragments in real time. Return type changed from `void` to `StreamResult` (backward-compatible when callers ignore the return value).
- Feature: `StreamingToolCallingLoop` — streaming counterpart of `ToolCallingLoop`; drives multi-round tool use via `chatStream`, with injectable `$onDelta`, `$executeTool`, `$onToolCall`, and `$onToolCallChunk` hooks.
- Docs: `examples/stream_tools_chain.php` — streaming tool loop using `StreamingToolCallingLoop` and `PredefinedTools`.

## 1.8.0 — 2026-05-13

- Feature: `ToolCallingLoop` runs OpenAI-style multi-round tool execution (assistant replay with `tool_calls`, strict JSON argument decoding, `Role::Tool` replies, follow-up `chatCompletions`) with injectable `callable` tool executor, optional `onToolCall` / `afterRoundCompletion` hooks, and `RuntimeException` when responses lack `choices[0]`.
- Refactor: `examples/tools_chain.php` and `examples/web_lookup_chain.php` delegate the tool loop to `ToolCallingLoop`.
- Tests: `tests/tool_calling_loop_test.php` covers idle pass-through, one tool round, invalid JSON arguments, callback hook, and error handling.

## 1.8.1 — 2026-05-13

- Docs: README intro clarifies configurable tool calling (schemas + executors, multi-step loops) and highlights `PredefinedTools` workflows (`grep`, `web_search`, `fetch_web_page`, …), not solely custom PHP functions.

## 1.7.4 — 2026-05-13

- Fix: `web_search` migrated from `api.duckduckgo.com` JSON Instant Answer API (always returned empty for most queries) to `html.duckduckgo.com/html/` HTML scraping; now returns `results[]` with `title`, `url`, `snippet` for real ranked results; adds optional `max_results` parameter (default 8, max 20); removes unused `flattenDdgTopics`.
- Fix: corrected `result__a` href regex (`//duckduckgo.com/l/?uddg=` prefix instead of `/l/?uddg=`); parser now extracts URLs and titles correctly.

## 1.7.3 — 2026-05-13

- Feature: Windows outbound HTTPS defaults to `CURLSSLOPT_NATIVE_CA` when no usable `TIVINS_LLAMA_CURL_CAINFO` is set (PHP 8.2+); env `TIVINS_LLAMA_CURL_WINDOWS_NATIVE_CA=1` prefers the OS trust store even when `TIVINS_LLAMA_CURL_CAINFO` is set (`=0` disables native CA); PEM fallback kept when native CA is unavailable or forced mode cannot apply.
- Feature: SSL-related `web_search` / `fetch_web_page` failures include `curl_errno` where relevant and a concrete `hint` for corporate TLS inspection.
- Docs: `examples/web_lookup_chain.php` explains mozilla-bundle vs OS store vs `WINDOWS_NATIVE_CA`.

## 1.7.2 — 2026-05-13

- Feature: configurable TLS for `web_search` / `fetch_web_page` — env `TIVINS_LLAMA_CURL_CAINFO` (PEM bundle), env `TIVINS_LLAMA_HTTP_SSL_VERIFY`, and `PredefinedTools::setHttpSslVerifyPeer()` for trusted dev environments without CA bundle (e.g. Windows PHP).
- Docs: `examples/web_lookup_chain.php` documents SSL troubleshooting.

## 1.7.1 — 2026-05-13

- Docs: `examples/web_lookup_chain.php` — multi-round tool loop like `examples/tools_chain.php`, restricted to `web_search` + `fetch_web_page` with system guidance for precise web facts (French demo prompt).

## 1.7.0 — 2026-05-13

- Feature: `PredefinedTools::getFetchWebPageTool()` / `fetch_web_page` — HTTP GET for http/https URLs via cURL, optional `max_bytes` cap (default 512 KiB, max 2 MiB), redirects limited to http/https; clarifies `web_search` description (DuckDuckGo summaries vs full pages).

## 1.6.0 — 2026-05-13

- Feature: `PredefinedTools` adds `grep` (recursive plain-text / PCRE scan), `web_search` (DuckDuckGo JSON API via cURL), `apply_diff` (`patch` on PATH), `git_status`, and `run_phpunit` (invokes `PHP_BINARY` + PHPUnit script); `all()`, `getExecuteTools()`, and `runTool()` dispatch match.
- Tests: `tests/predefined_tools_test.php` covers grep, git status in repo, missing PHPUnit path, optional web search JSON shape, and apply-diff outcomes.

## 1.5.1 — 2026-05-13

- Feature: `PredefinedTools::runTool()` dispatches predefined executors and normalizes outputs for `Role::Tool` messages; `readFile` / `writeFile` now report failures honestly (`string|false`, `bool`), treat empty-path arguments as failures, preserve empty-file reads as empty strings (not failures), and use silent I/O for expected misses so tooling is not drowned in notices; `getDateTime()` accepts optional parameters for callable parity.
- Docs: `examples/tools_chain.php` — multi-round tool loop, strict JSON decoding of arguments, schemas from `PredefinedTools::all()`; `examples/completions.php` — tool schemas wired to `PredefinedTools::all()`.
- Tests: `tests/predefined_tools_test.php` — dispatcher and I/O behaviour (no LLM server).

## 1.5.0 — 2026-05-12

- Feature: `Message` supports optional `toolCallId`, `name`, and `toolCalls` for OpenAI-compatible tool follow-ups; `Conversation::toChatCompletionMessages()` emits `tool_calls` on assistant messages and `tool_call_id` on tool messages.
- Docs: `examples/completions.php` — correct tool round-trip (assistant replay + tool results) after `finish_reason: tool_calls`.

## 1.4.1 — 2026-05-12

- Docs: `examples/completions.php` — tools example uses valid JSON Schema for `parameters` (`type`, `properties`, `required`, `additionalProperties`) so models bind consistently to argument names like `file_path`.
- Docs: `ChatFunctionTool` — class doc warns against invalid shorthand parameter maps.

## 1.4.0 — 2026-05-12

- Feature: function tools — `ChatFunctionTool` builds OpenAI-style tool entries; `ChatCompletionOptions` gains `tools` and `tool_choice` (merged into chat completion requests). No automatic handling of `tool_calls` responses or tool execution.
- Docs: `examples/chat_tools.php` — `chatCompletions()` with tools and pretty-printed raw JSON response.

## 1.3.0 — 2026-05-12

- Feature: `ChatCompletionOptions` — OpenAI-style chat completion parameters (`temperature`, `top_p`, `max_tokens`, `frequency_penalty`, `presence_penalty`, `seed`, `stop`, `n`) with documented semantics; optional third argument to `Lama::chatStream`, optional second argument to `Lama::chat` / `Lama::chatCompletions`.
- Tests: `tests/chat_completion_options_test.php` — serialization and request-body merge checks (no server required).
- Docs: README and `examples/exemples.php` — usage of `ChatCompletionOptions` with `chat()` / `chatStream()`.

## 1.2.3 — 2026-05-10

- Docs: `examples/mediation.php` — multi-party mediation demo using `BehaviorPrompts::MEDIATOR`; three individuals in conflict over a product launch, three mediator rounds (initial analysis, constraint added, decision-process request).

## 1.2.2 — 2026-05-10

- Feature: `BehaviorPrompts` — add `SUMMARIZER`, `MODERATOR`, and `MEDIATOR` personas.

## 1.2.1 — 2026-05-09

- Docs: `examples/exemples.php` — demo of `Translator::translate()` and `translateBatch()`.

## 1.2.0 — 2026-05-09

- Feature: `BehaviorPrompts::TRANSLATOR` and overhauled `Translator` — dedicated translation system prompt, structured user message (no assistant prefill), optional FIFO translation cache (`translationCacheMaxEntries`), and `translateBatch()` for one API call over multiple segments (JSON request/response with markdown-fence tolerance).

## 1.1.0 — 2026-05-09

- Feature: add `ThinkingPrompts` value object to make `ThinkingChat` system prompts configurable. Both phases default to the previous hard-coded instructions (fully backwards-compatible). Pass a custom `ThinkingPrompts` instance to override the reasoning or answering persona without touching orchestration logic.

## 1.0.2 — 2026-05-09

- Refactor: centralize cURL execution and JSON response parsing in `Lama` (`executeCurlForJson`, `decodeJsonArrayResponse`, `assertCurlSucceeded`) to remove duplication between `httpGetJson`, `request`, and streaming error handling.
