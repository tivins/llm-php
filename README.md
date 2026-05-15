# LLM-PHP

Goal: Run LLM inference locally on a machine with **8 GB of VRAM or more**.

Stack: Llama.cpp + a lightweight model (Gemma 2 9B, Gemma 4 4B, Qwen 2.5 7B, …).

Beyond exposing llama.cpp from PHP, **llm-php** adds higher-level helpers—such as "thinking"-style prompting, preset personas, and **configurable** tool calling: you declare tools (schemas + bound executors) and run multi-step loops until the model is done. That is not limited to ad hoc PHP callables—`PredefinedTools` ships ready-made workflows the model can drive (for example `grep`, `web_search`, `fetch_web_page`, file read/write, `apply_diff`, `git_status`, and more).

## Installation

### llm-php

```shell
composer require tivins/llm-php
```

### llama.cpp

[https://github.com/ggml-org/llama.cpp/blob/master/docs/install.md](https://github.com/ggml-org/llama.cpp/blob/master/docs/install.md)

```shell
apt install llama-cpp    # linux
brew install llama.cpp   # mac/linux
winget install llama.cpp # windows
```

API Doc : [https://github.com/ggml-org/llama.cpp/blob/master/tools/server/README.md](https://github.com/ggml-org/llama.cpp/blob/master/tools/server/README.md)

### Downloading a model (≤ 6.5 GB)

Pick a [GGUF](https://github.com/ggml-org/ggml/blob/master/docs/gguf.md) file that leaves headroom on your VRAM—the KV cache and GPU drivers use memory too. On an **8 GB VRAM** card, aim for roughly **about 5 to 6.5 GB** for model weights in practice, depending on quantization and context length.

- [https://huggingface.co/bartowski/google_gemma-4-E4B-it-GGUF](https://huggingface.co/bartowski/google_gemma-4-E4B-it-GGUF) (google_gemma-4-E4B-it-Q5_K_M.gguf or google_gemma-4-E4B-it-Q6_K.gguf)
- [https://huggingface.co/bartowski/gemma-2-9b-it-GGUF](https://huggingface.co/bartowski/gemma-2-9b-it-GGUF) (gemma-2-9b-it-Q4_K_M.gguf)
- [https://huggingface.co/bartowski/Qwen2.5-7B-Instruct-GGUF](https://huggingface.co/bartowski/Qwen2.5-7B-Instruct-GGUF) (Qwen2.5-7B-Instruct-Q4_K_M)

## PHP usage

**Minimal example:**

First, run llama.cpp server (run.sh, run.bat).

```php
$lama = Lama::fromServerUrl('http://127.0.0.1:8080');
$conversation = new Conversation();
$conversation->addMessage(new Message(Role::System, BehaviorPrompts::HELPFUL));
$conversation->addMessage(new Message(Role::User, 'List and briefly explain five practical habits that improve learning retention, with one short paragraph per habit (about 3–5 sentences each).'));
$answer = trim($lama->chat($conversation));
```

Note: This example is simplified, it does not handle exceptions and does not check whether the LLM is reachable (health).

See the `examples` folder for more.

**Sampling and generation options** (OpenAI-compatible body fields such as `temperature`, `top_p`, `max_tokens`, penalties, `seed`, `stop`, `n`) are passed via `ChatCompletionOptions` as an optional argument to `chat()`, `chatCompletions()`, and `chatStream()`. Only properties you set are sent; omitted fields keep the server defaults. See the class docblock on `ChatCompletionOptions` for parameter meanings and compatibility notes for local backends.

```php
use Tivins\Llama\ChatCompletionOptions;

$sampler = new ChatCompletionOptions(temperature: 0.4, top_p: 0.9, max_tokens: 256, seed: 42);
$answer = trim($lama->chat($conversation, $sampler));
```

## Tests et diagnostic stream

Run unit checks with `php tests/<name>_test.php` (see `tests/*_test.php`). `tests/normalized_turn_outcome_test.php` replays a static SSE fixture (`tests/fixtures/sse_chat_stream_enriched_fixture.sse.txt`) through `ChatStreamAccumulator`, asserting aggregation of `content`, `reasoning_content`, `tool_calls`, `finish_reason`, and `usage` without a live LLM server. `tests/examples_env_loader_test.php` checks that `examples/.env` is applied by `example_load_examples_env_file()` when `TIVINS_LLAMA_CONVERSATION_LOG` is not already set in the environment. **`tests/human_turn_renderer_test.php`** exercises `HumanTurnRenderer` and stream display delegates with in-memory stdout/stderr.

`tests/stream_probe.php` remains an **interactive** script (against a running local server) to classify whether a backend emits **cumulative** vs **incremental** `content` deltas. It complements, but does not replace, these OpenAI-shaped parsing fixtures; no change to `stream_probe.php` was required for Étape 4.

## Conversation logging (JSONL)

Optional audit logs use **`TurnJsonlLogger`** (`Tivins\Llama\TurnJsonlLogger`): one JSON object per line from **`TurnRecord::toLogArray()`**. Set environment variable **`TIVINS_LLAMA_CONVERSATION_LOG`** to a file path before running migrated examples (`examples/chat.php`, tool demos, etc.), or rely on **`examples/.env`** (read when **`examples/_helpers.php`** is loaded and the logger helper runs); logs go under `examples/logs/` by convention (ignored by git — see `.gitignore`). For streaming, **`Lama::chatStream(..., ?SsePayloadCapture $capture)`** records verbatim SSE JSON payloads into **`RawStreamTrace::$rawDataLines`** (structured **`StreamEvent`** replay lists stay optional / empty in this path). Avoid logging if responses might contain secrets once you attach remote backends or API keys.

## Human-readable console output (`HumanTurnRenderer`)

**`HumanTurnRenderer`** renders **`NormalizedTurnOutcome`** or **`TurnRecord`** (replay from JSONL) with reasoning on stderr by default. **`examples/stream_*_chain.php`** and **`examples/chat.php`** use **`HumanTurnStreamDisplay`** for streamed reply tokens and reasoning deltas (options from **`example_render_options_from_env()`**).

In examples, **`print_output()`** delegates to **`HumanTurnRenderer::renderCompletionPayload()`** after **`examples/.env`** is loaded. **`TIVINS_LLAMA_COMPLETION_DUMP_RAW=1`** restores the verbose legacy diagnostics. **`TIVINS_LLAMA_NO_ANSI=1`** and **`TIVINS_LLAMA_REASONING_STDOUT=1`** tweak colours and where reasoning prints (via **`example_render_options_from_env()`**).

Regression coverage: **`tests/human_turn_renderer_test.php`** (memory streams; colors off). On Windows PowerShell, use Windows Terminal when leaving ANSI escapes enabled.

