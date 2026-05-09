
set LLM_MODEL=../models/google_gemma-4-E4B-it-Q6_K.gguf

llama-server.exe -m %LLM_MODEL% --port 8080 --no-webui
