# Local LLMs

Objectif: Faire de l'inférence avec un LLM en local sur une machine avec 8Go de VRAM ou plus.

Stack: Llama.cpp + un modèle léger (Gemma 2 9B, Gemma 4 4.5B, Qwen 2.5 7B, ...).

## Installation

### llama.cpp

https://github.com/ggml-org/llama.cpp/blob/master/docs/install.md

```shell
winget install llama.cpp # windows
brew install llama.cpp   # mac/linux
```

### Télécharger le modèle (<= 6.5Go)

Choisissez un fichier [GGUF](https://github.com/ggml-org/ggml/blob/master/docs/gguf.md) dont la taille laisse de la marge sur votre VRAM (le cache KV et le pilote GPU consomment aussi de la mémoire). Sur une carte **8 Go de VRAM**, visez en pratique **environ 5 à 6,5 Go** pour les poids du modèle, selon la quantification et la taille du contexte.

* https://huggingface.co/bartowski/google_gemma-4-E4B-it-GGUF (google_gemma-4-E4B-it-Q5_K_M.gguf or google_gemma-4-E4B-it-Q6_K.gguf)
* https://huggingface.co/bartowski/gemma-2-9b-it-GGUF (gemma-2-9b-it-Q4_K_M.gguf)
* https://huggingface.co/bartowski/Qwen2.5-7B-Instruct-GGUF (Qwen2.5-7B-Instruct-Q4_K_M)

## PHP part

**Exemple minimal:**

```php
$lama = Lama::fromServerUrl('http://127.0.0.1:8080');
$conversation = new Conversation();
$conversation->addMessage(new Message(Role::System, "You are a helpful assistant."));
$conversation->addMessage(new Message(Role::User, 'List and briefly explain five practical habits that improve learning retention, with one short paragraph per habit (about 3–5 sentences each).'));
$answer = trim($lama->chat($conversation));
```

NB: Cet exemple est simplifié, car il ne traite pas les exceptions et ne teste pas si le LLM est disponible (health).

Consultez le dossier `exemples` pour plus d'informations.
