# Plan : modèle de conversation moderne, données brutes et journalisation

Ce document sert de **référence unique** pour implémenter les évolutions discutées (DTO brut, historique fidèle, logs JSON complets, affichage humain riche). Il est découpé en **étapes séquentielles** avec **critères de validation** avant de passer à la suite.

**Principes directeurs**

1. **Séparer trois couches** : (a) données brutes / audit, (b) modèle domaine rejouable vers l’API (`Message` / `Conversation`), (c) présentation humaine.
2. **Préserver la donnée brute au maximum** : préférer stocker la réponse HTTP décodée telle quelle (non-stream) ou une trace reconstituable (stream), plutôt que de n’exposer qu’un sous-ensemble.
3. **Les exemples** doivent pouvoir écrire **l’intégralité du tour** en **JSON** (fichier ou JSONL), en plus de l’affichage console.

**Ordre des phases** : respecter l’ordre ci-dessous sauf mention contraire ; certaines phases peuvent être fractionnées en PR distinctes tant que les validations de la phase précédente sont vertes.

---

## Étape 0 — Cadrage et inventaire (sans code métier)

**Objectif** : figer le périmètre et les artefacts à toucher pour éviter la dérive entre conversations.

**Actions**

- Lister les points d’entrée actuels :
  - `Lama::chat()`, `Lama::chatCompletions()`, `Lama::chatStream()`, `StreamResult`
  - `ToolCallingLoop`, `StreamingToolCallingLoop`
  - `Message`, `Conversation`, `ThinkingChat` (hors scope immédiat sauf alignement doc)
  - Exemples : `examples/chat.php`, `examples/_helpers.php`, `examples/*_chain.php`, `examples/workspace_tools_demo.php`, `examples/stream_*.php`, `tests/stream_probe.php`
- Décider du **format de fichier de log** pour les exemples :
  - **Recommandation** : **JSONL** (une ligne JSON par événement ou par tour) pour append sans recharger tout le fichier ; alternative : un tableau JSON réécrit à chaque tour pour les scripts très courts.
- Décider si `usage` en stream doit être parsé (certains backends envoient un chunk final avec `usage`) — à traiter en phase 4 si pertinent.

### Inventaire vérifié dans le dépôt (2026-05-15)

Artefacts mentionnés dans le plan — **tous présents** sous `src/Tivins/Llama/` (PSR-4) :

| Domaine | Fichiers |
|---------|----------|
| Client HTTP / tours | `Lama.php` (`chat`, `chatCompletions`, `chatStream`), `StreamResult.php` |
| Boucles d’outils | `ToolCallingLoop.php`, `StreamingToolCallingLoop.php` |
| Modèle conversation | `Message.php`, `Conversation.php`, `ThinkingChat.php` |

Exemples couverts par la liste initiale du plan :

| Fichier | Rôle (rappel) |
|---------|----------------|
| `examples/chat.php` | Chat stream simple |
| `examples/_helpers.php` | Helpers sortie console |
| `examples/tools_chain.php` | Chaîne outils (non-stream) |
| `examples/stream_tools_chain.php` | Chaîne outils (stream) |
| `examples/web_lookup_chain.php` | Chaîne web lookup |
| `examples/stream_web_lookup_chain.php` | Idem stream |
| `examples/workspace_tools_demo.php` | Démo workspace / conversation réutilisée |
| `examples/completions.php` | Complétions |

Scripts `examples/*.php` **non** listés au plan mais présents (priorité basse ou hors périmètre logging jusqu’aux étapes 5–6 si besoin) : `chat_tools.php`, `exemples.php`, `mediation.php`, `moderation.php`, `tokenize.php`.

Tests pertinents pour la suite du plan :

| Fichier |
|---------|
| `tests/stream_probe.php` |
| `tests/tool_calling_loop_test.php` |
| `tests/chat_completion_options_test.php` |
| `tests/predefined_tools_test.php` |

**Validation (checklist)**

- [x] Le chemin du document de plan est connu : `docs/conversation-modernization-plan.md`.
- [x] Accord sur JSONL vs JSON array pour les exemples (noter la décision en bas de ce fichier ou dans une issue).

**Sortie** : cette section et la section « Décisions à tracer » mises à jour ; aucun livrable code requis à l’étape 0.

---

## Étape 1 — DTO « brut » et enregistrement de tour (`TurnRecord`)

**Objectif** : introduire des structures **sérialisables en JSON** qui capturent une réponse complète ou une trace stream, **sans encore** modifier le comportement des boucles d’outils ni tous les exemples.

**Implémentation (2026-05-15)** : classes sous `src/Tivins/Llama/Dto/` ; tests `tests/turn_record_test.php` ; fixtures `tests/fixtures/chat_completion_response_min.json`, `tests/fixtures/turn_record_completion_expected.json`.

### 1.1 Types / classes proposés (noms indicatifs)

| Artefact | Rôle |
|----------|------|
| `RawChatCompletionResponse` | Enveloppe typée autour de `array<string,mixed>` retourné par `Lama::chatCompletions()` ; méthodes utilitaires optionnelles (`firstChoiceMessage()`, `usage()`, etc.) sans perdre le tableau brut. |
| `StreamEvent` ou équivalent | Petit value object pour un événement dérivé d’un chunk SSE parsé : ex. `kind` (`content_delta`, `reasoning_delta`, `tool_arguments_fragment`, `finish`, `usage`, `raw_chunk`), `payload` (array ou string selon cas). |
| `RawStreamTrace` | Liste ordonnée d’événements + métadonnées (optionnel : garder aussi les lignes `data:` brutes pour debug byte-identique). |
| `TurnRecord` | Agrège : identifiant de tour (uuid ou incrément), timestamp ISO8601, snapshot optionnel des options de requête (`ChatCompletionOptions` → `array` pour sérialisation), mode (`completion` \| `stream`), soit `RawChatCompletionResponse` soit `RawStreamTrace`, et le **`StreamResult` final** pour le stream (ou équivalent sérialisé : `content`, `finishReason`, `toolCalls`, `reasoningContent`). |

**Emplacement suggéré** : `src/Tivins/Llama/Dto/` ou directement sous `src/Tivins/Llama/` selon la préférence du dépôt (rest cohérent avec PSR-4 existant).

### 1.2 Sérialisation JSON

- `TurnRecord::toLogArray(): array` doit produire une structure **encodeable en JSON** (`json_encode` sans erreur), en incluant le payload brut (réponse complète non-stream ou trace stream).
- Éviter les objets non sérialisables ; documenter les champs optionnels.

### 1.3 Intégration minimale dans `Lama` (optionnel à cette étape)

- Soit ajouter des méthodes parallèles (`chatCompletionsLogged`, etc.) — **déconseillé** si trop de duplication.
- Soit préparer **Étape 4** où `Lama` acceptera un `?TurnSink` / callback pour enregistrer brut + résultat — à l’étape 1, se limiter aux **classes + tests** suffit.

**Validation**

- [x] Tests unitaires : `TurnRecord::toLogArray()` → `json_encode` OK ; round-trip ou Golden file minimal sur un fixture JSON de réponse chat completion (sans dépendre du réseau).
- [x] `composer` autoload : nouvelles classes découvertes sans erreur.
- [x] Aucune régression sur les tests existants (`tests/`).

**Critère de passage** : tests verts + revue du schéma JSON documentée en PHPDoc ou dans un fichier `docs/turn-record-schema.md` (optionnel, seulement si le schéma devient volumineux).

---

## Étape 2 — Correction des boucles d’outils : historique `Conversation` complet

**Objectif** : corriger l’incohérence actuelle où **le message assistant final** (sans `tool_calls`) n’est **pas** ajouté à `Conversation` après `ToolCallingLoop::runUntilIdle()` et `StreamingToolCallingLoop::runUntilIdle()`.

**Implémentation (2026-05-15)** : `ToolCallingLoop` et `StreamingToolCallingLoop` ajoutent le tour assistant final ; épuisement de `$maxRounds` avec encore des `tool_calls` → `RuntimeException`. Tests étendus dans `tests/tool_calling_loop_test.php` (fakes stream + exhaustion). Bump **1.14.0** — voir `CHANGELOG.md`.

### 2.1 Comportement attendu

Après la boucle, `$conversation` doit contenir, dans l’ordre :

1. Messages préexistants (system, user, …).
2. Pour chaque round d’outils : **un** message `assistant` avec `content` + `tool_calls` (comme aujourd’hui pour les rounds intermédiaires).
3. Messages `tool` correspondants.
4. **Un dernier message `assistant`** avec le contenu final (`content`, éventuellement `reasoning_content` une fois l’étape 3 faite) et **sans** `tool_calls` lorsque le modèle a terminé sans nouvel appel.

### 2.2 Fichiers

- `src/Tivins/Llama/ToolCallingLoop.php`
- `src/Tivins/Llama/StreamingToolCallingLoop.php`

### 2.3 Cas limites

- `content` vide mais `tool_calls` présents — inchangé pour les rounds intermédiaires.
- Tour final : `content` vide et pas d’outils (rare) — ajouter quand même un message assistant cohérent avec le protocole OpenAI (`null` vs `''` selon ce que `Conversation::toChatCompletionMessages()` impose).
- `maxRounds` atteint alors que le dernier résultat est encore `tool_calls` — **`RuntimeException`** explicite (message indiquant d’augmenter `$maxRounds` ou d’inspecter la charge utile / le `StreamResult`) ; aucun message assistant final inventé.

**Validation**

- [x] Nouveaux tests dans `tests/tool_calling_loop_test.php` : après `runUntilIdle`, dernier message de `Conversation` est `Role::Assistant` sans `tool_calls` et correspond au contenu du dernier choix / `StreamResult`.
- [x] Test miroir streaming vs non-streaming sur scénario mocké (`FakeLama` / `FakeStreamLama`).
- [x] Exemples multi-tours réutilisant une `Conversation` (`examples/workspace_tools_demo.php`, etc.) : comportement compatible (aucun ajout manuel dupliquant le tour final nécessaire) — revue statique après correctif ; exécution locale optionnelle.

**Critère de passage** : tests boucles verts ; comportement documenté dans PHPDoc des deux classes.

---

## Étape 3 — `Message` et `Conversation` : schéma moderne (reasoning, compat API)

**Objectif** : permettre à l’historique persisté en mémoire de refléter les champs que le backend peut renvoyer, en particulier **`reasoning_content`** sur les messages assistant, tout en restant compatible avec OpenAI-style chat completions.

**Implémentation (2026-05-15)** : `Message::$reasoningContent` + `Message::normalizeReasoningContent()` (`null` et `''` → absent à la sérialisation). `Conversation::toChatCompletionMessages()` n’ajoute la clé JSON que pour les assistants lorsque la valeur est non vide. `ToolCallingLoop` lit `choices[0].message.reasoning_content` ; `StreamingToolCallingLoop` reprend `StreamResult::$reasoningContent`. Note PHPDoc sur `ThinkingChat`. Tests dans `tests/chat_completion_options_test.php`. Bump **1.15.0**.

### 3.1 Extension de `Message`

- Ajouter un paramètre optionnel `?string $reasoningContent = null` (nom aligné sur la clé JSON `reasoning_content`).
- Règles : nullable vs chaîne vide — **choisir une convention** (ex. normaliser `''` → traité comme absent dans la sérialisation) et l’appliquer partout.

### 3.2 `Conversation::toChatCompletionMessages()`

- Pour `Role::Assistant` : inclure `'reasoning_content' => ...` **uniquement** lorsque non vide (ou selon convention serveur — documenter si certains backends exigent la clé présente).
- Ne pas casser les messages avec `tool_calls` : ordre des clés peu important pour JSON ; respecter les contraintes actuelles (`content` null vs string pour tool-call-only assistants).

### 3.3 Migrer les call sites internes

- `ToolCallingLoop` / `StreamingToolCallingLoop` : lors de l’ajout du message assistant (intermédiaire et **final**), transmettre `reasoning_content` si disponible dans la réponse brute / `StreamResult`.
- `ThinkingChat` : **pas d’obligation** de changer le protocole deux-phases ; documenter que ce n’est pas équivalent à `reasoning_content` natif.

**Validation**

- [x] Tests unitaires `tests/chat_completion_options_test.php` ou nouveau fichier : `toChatCompletionMessages()` contient `reasoning_content` quand défini.
- [x] Test de non-régression : conversations sans reasoning inchangées.
- [x] Revue manuelle : `Lama::chatStream()` alimente déjà `StreamResult::$reasoningContent` — vérifier que ce champ peut être poussé dans le **message assistant final** après étape 2.

**Critère de passage** : tests verts ; exemple minimal (peut être interne au test) montrant assistant avec reasoning + content sérialisés correctement.

---

## Étape 4 — Normalisation « résultat de tour » et parsing stream enrichi

**Objectif** : une seule structure **`NormalizedTurnOutcome`** (nom indicatif) dérivée du brut, utilisable pour affichage humain et pour compléter `TurnRecord`, sans que chaque exemple re-parse à la main.

**Implémentation (2026-05-15)** : `src/Tivins/Llama/Dto/NormalizedTurnOutcome.php` avec `fromChatCompletionArray()` et `fromStreamResult(StreamResult, ?array usage)` (le paramètre `usage` surcharge `StreamResult::$usage` quand fourni). Parsing SSE factorisé dans `ChatStreamAccumulator` (utilisé par `Lama::chatStream()` et les tests). **`StreamResult`** inclut `usage`, `model`, `id` optionnels alimentés par le **dernier** chunk JSON qui les expose (forme `usage` variable selon backend). **`TurnRecord::toLogArray()`** reflète ces champs sur `stream_result` lorsqu’ils sont non nuls. Fixture SSE : `tests/fixtures/sse_chat_stream_enriched_fixture.sse.txt`. Bump **1.16.0**.

### 4.1 Contenu suggéré de `NormalizedTurnOutcome`

- `content: string`
- `reasoningContent: string`
- `toolCalls: list<array>` (format OpenAI)
- `finishReason: string`
- `usage: ?array` (présent si disponible)
- `model: ?string`, `id: ?string` si présents dans le brut non-stream
- références vers indices dans le brut (optionnel)

### 4.2 Fabriques

- `NormalizedTurnOutcome::fromChatCompletionArray(array $response): self`
- `NormalizedTurnOutcome::fromStreamResult(StreamResult $result, ?array $usage = null): self`

### 4.3 `Lama::chatStream` — usage / chunks

- Si décision Étape 0 : parser les événements SSE qui portent `usage` et les agréger dans `StreamResult` **ou** dans `TurnRecord` uniquement — **étendre `StreamResult`** avec `public ?array $usage = null` seulement si c’est stable pour tous les backends ; sinon garder `usage` uniquement dans la trace brute et dans `NormalizedTurnOutcome`.

**Décision Étape 4** : **`StreamResult::$usage`** (et `model` / `id` lorsque présents sur les chunks) est rempli depuis le dernier événement JSON qui expose ces clés ; la valeur demeure **`null`** tant que le backend ne les envoie pas — la **forme du tableau `usage`** n’est pas normalisée (même stratégie que le JSON brut). `NormalizedTurnOutcome::fromStreamResult()` lit ces champs par défaut avec possibilité de surcharge explicite de `usage`. La trace brute `RawStreamTrace` reste le lieu des événements finement typés lorsque nécessaire (étapes ultérieures).

**Validation**

- [x] Tests avec fixtures SSE (fichiers dans `tests/fixtures/` ou chaînes inline) couvrant : deltas `content`, `reasoning_content`, `tool_calls` fragments, `finish_reason`, éventuellement `usage`.
- [x] Comparaison ou contrat clair avec `tests/stream_probe.php` si utilisé en manuel — documenter dans README ou dans ce plan si le probe doit être mis à jour.

**Critère de passage** : une seule fonction de normalisation utilisée par les futurs exemples ; pas de duplication de logique entre stream et completion dans les scripts.

---

## Étape 5 — Journalisation fichier (exemples + helper réutilisable)

**Objectif** : permettre à **tous** les exemples pertinents d’écrire **l’intégralité** du tour en JSON.

### 5.1 API suggérée

- Classe `SessionJsonlLogger` (ou `TurnJsonlLogger`) dans `src/` ou `examples/` :
  - constructeur : chemin fichier, options (append, pretty-print désactivé pour JSONL standard).
  - `logTurn(TurnRecord $record): void`
  - gestion erreurs : `RuntimeException` ou log STDERR selon convention du projet.

### 5.2 Migration des exemples (par priorité)

| Priorité | Fichier | Action |
|----------|---------|--------|
| Haute | `examples/chat.php` | Capturer `StreamResult`, construire `TurnRecord`, logger JSONL ; persistance « session rejouable » peut rester un second fichier ou fusionner dans un seul artefact documenté. |
| Haute | `examples/workspace_tools_demo.php`, `examples/tools_chain.php`, `examples/stream_tools_chain.php` | Logger chaque tour / chaque round si nécessaire (documenter : une ligne par round vs une ligne par tour utilisateur). |
| Moyenne | `examples/web_lookup_chain.php`, `examples/stream_web_lookup_chain.php` | Idem. |
| Basse | `examples/completions.php`, `examples/_helpers.php` | Remplacer ou compléter `print_output` par un helper qui dump brut vers fichier si flag env / CLI ; garder sortie humaine via Étape 6. |

### 5.3 `.gitignore`

- Ajouter un motif pour les logs générés (ex. `examples/logs/`, `*.session.jsonl`) pour éviter les commits accidentels.

**Validation**

- [ ] Exécuter au moins un exemple en local avec logger activé ; vérifier que le fichier contient le payload brut attendu.
- [ ] Pas de secrets dans les logs (pas de clés API — normalement absent pour llama.cpp local ; rester vigilant pour extensions futures).

**Critère de passage** : au moins **deux** exemples migrés + README ou commentaire en tête des exemples expliquant la variable d’environnement ou le chemin de log.

---

## Étape 6 — Affichage humain unifié (`HumanTurnRenderer`)

**Objectif** : factoriser l’affichage console (stdout/stderr) pour montrer **autant que possible** : reasoning, texte, appels d’outils, fragments stream optionnels.

### 6.1 API suggérée

- `HumanTurnRenderer::renderNormalized(NormalizedTurnOutcome $out, RenderOptions $opts): void`
- `HumanTurnRenderer::renderTurnRecord(TurnRecord $record, RenderOptions $opts): void` — pour rejouer depuis log.

### 6.2 Options

- Couleurs ANSI activables/désactivables.
- Canaux : reasoning → STDERR par défaut (comme certains exemples actuels) ou tout stdout — **uniformiser** et documenter.

### 6.3 Décommission progressive

- `examples/_helpers.php` : `print_output` devient un wrapper vers renderer + option « dump brut » ou reste pour debug rapide non-stream — décider explicitement pour éviter deux philosophies concurrentes.

**Validation**

- [ ] Sortie lisible sur Windows (PowerShell) et conventions ANSI — documenter limitations si nécessaire.
- [ ] Au moins un test snapshot faible (chaîne normalisée sans couleurs) ou test manuel checklist dans README.

**Critère de passage** : exemples migrés utilisent le renderer au lieu de logique dupliquée (`lastDeltaSource`, etc.) où c’est raisonnable.

---

## Étape 7 — Documentation, changelog, version

**Objectif** : rendre le tout exploitable pour les contributeurs et conformément aux règles du dépôt.

**Actions**

- `README.md` : section « Conversation logging & modern message fields ».
- `CHANGELOG.md` (si présent) ou suivre la convention du projet ; bump **semver** dans `composer.json` :
  - Extension de `Message` avec argument optionnel → généralement **minor** si BC préservée.
  - Changement de comportement des boucles d’outils → **minor** avec note **behavior change** dans changelog.
- Mentionner la migration pour tout code utilisateur qui construisait `Message` en positionnel — PHP 8 named params réduisent la casse.

**Validation**

- [ ] Changelog à jour ; version cohérente.
- [ ] Ce plan mis à jour : cocher les étapes réalisées ou ajouter une section « État » en fin de document.

---

## Dépendances entre étapes (résumé)

```
Étape 0 (cadrage)
    ↓
Étape 1 (DTO / TurnRecord + tests)
    ↓
Étape 2 (fix boucles outils + tests)     ← peut être parallélisée minimal avec 1 si équipes différentes, mais risque de conflits sur Conversation/Message
    ↓
Étape 3 (Message + reasoning + Conversation)
    ↓
Étape 4 (NormalizedTurnOutcome + usage stream si applicable)
    ↓
Étape 5 (logger fichier + exemples)
    ↓
Étape 6 (HumanTurnRenderer + nettoyage exemples)
    ↓
Étape 7 (docs + semver)
```

**Recommandation** : en pratique enchaîner **1 → 2 → 3** dans cet ordre minimise les conflits et les messages assistant incomplets pendant l’ajout de `reasoning_content`.

---

## État d’avancement (à mettre à jour au fil des conversations)

| Étape | Statut | Notes / PR / commit |
|-------|--------|---------------------|
| 0 | ☑ Terminé | Inventaire 2026-05-15 ; décisions JSONL + dossier logs — voir « Décisions à tracer » |
| 1 | ☑ Terminé | DTO `src/Tivins/Llama/Dto/` ; `tests/turn_record_test.php` + fixtures JSON |
| 2 | ☑ Terminé | Correction historique assistant final + erreur si `maxRounds` trop bas ; bump 1.14.0 |
| 3 | ☑ Terminé | `reasoning_content` sur `Message` + sérialisation ; boucles outils ; bump 1.15.0 |
| 4 | ☑ Terminé | `NormalizedTurnOutcome` + `ChatStreamAccumulator` ; `StreamResult` usage/model/id ; tests + fixture SSE ; bump 1.16.0 |
| 5 | ☐ Non démarré | |
| 6 | ☐ Non démarré | |
| 7 | ☐ Non démarré | |

---

## Décisions tracées (étape 0 — complété le 2026-05-15)

- **Format fichier exemples** : **JSONL** (une ligne = un événement ou un tour, selon convention choisie à l’étape 5).  
  - **JSON array** : réservé aux scripts très courts qui réécrivent tout le fichier à chaque tour ; pas le format par défaut du projet.
- **`usage` en stream** : **étape 4** — voir § 4.3 et « Décision Étape 4 » : dernier chunk avec `usage` → `StreamResult::$usage` (nullable ; forme brute) ; surcharge possible via `NormalizedTurnOutcome::fromStreamResult(..., $usage)`.
- **Emplacement logs exemples** : **`examples/logs/`** (à ajouter au `.gitignore` à l’étape 5 ; fichiers nommés explicitement dans les exemples, ex. `*.session.jsonl` si utile).
