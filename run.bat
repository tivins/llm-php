
set LLM_PORT=8080
set LLM_MODEL=./models/google_gemma-4-E4B-it-Q6_K.gguf

echo Listening on http://127.0.0.1:%LLM_PORT%
llama-server.exe -m %LLM_MODEL% --port %LLM_PORT% --no-webui -lv 0
