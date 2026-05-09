# Changelog

## 1.0.2 — 2026-05-09

- Refactor: centralize cURL execution and JSON response parsing in `Lama` (`executeCurlForJson`, `decodeJsonArrayResponse`, `assertCurlSucceeded`) to remove duplication between `httpGetJson`, `request`, and streaming error handling.
