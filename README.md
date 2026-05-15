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

