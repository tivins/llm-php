# Changelog

## 1.1.0 — 2026-05-09

- Feature: add `ThinkingPrompts` value object to make `ThinkingChat` system prompts configurable. Both phases default to the previous hard-coded instructions (fully backwards-compatible). Pass a custom `ThinkingPrompts` instance to override the reasoning or answering persona without touching orchestration logic.

## 1.0.2 — 2026-05-09

- Refactor: centralize cURL execution and JSON response parsing in `Lama` (`executeCurlForJson`, `decodeJsonArrayResponse`, `assertCurlSucceeded`) to remove duplication between `httpGetJson`, `request`, and streaming error handling.
