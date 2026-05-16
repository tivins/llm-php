ajouter un tool "lint" qui prend en paramètre "language" et "file(s)".

selon l'extension du fichier, on détermine le language et le linter.

Si le host n'a pas l'outil accesible pour le langage donné, on retourne une erreur.

Sinon, on execute le lint et on renvoi la sortie brute.

ex: `php -l <file1> [<file2>...]`

Prévoir à minima les languages :

- php
- js
- ts
- java
- cs
- py
- md
- yml/yaml

-----------
GPT5-mini:

Voici une liste concise de commandes courantes pour linter du code source par langage.

JavaScript (ESLint)
```
# projet avec package.json
npx eslint <fichier|dossier> --ext .js,.jsx

# globalement installé
eslint <fichier|dossier> --ext .js,.jsx
```

TypeScript (ESLint + TypeScript parser / or TSLint déprécié)
```
# avec ESLint (recommandé)
npx eslint <fichier|dossier> --ext .ts,.tsx

# vérification de la compilation + erreurs TS (optionnel)
npx tsc --noEmit
```

Java (Checkstyle / Error Prone / javac)
```
# Checkstyle (avec fichier de configuration)
java -jar checkstyle-X.X-all.jar -c /google_checks.xml <fichier|dossier>

# using javac for basic syntax
javac <fichier>.java
```

PHP
```
# syntax check
php -l <fichier>.php

# PHP_CodeSniffer (sniffing / coding standards)
phpcs --standard=PSR12 <fichier|dossier>

# PHPStan / Psalm (analyse statique)
vendor/bin/phpstan analyse <dossier>
vendor/bin/psalm
```

Go
```
# go vet (vérifications vet)
go vet ./...

# gofmt (format) + check
gofmt -l -w .

# golangci-lint (agrégateur)
golangci-lint run ./...
```

Rust
```
# compilation + checks (inclut warnings)
cargo check

# clippy (linter idiomatique)
cargo clippy --all-targets --all-features
```

Markdown
```
# markdownlint-cli
npx markdownlint-cli '**/*.md'

# remark-cli (avec plugins)
npx remark . --frail
```

YAML
```
# yamllint
yamllint <fichier|dossier>

# kubectl (pour manifests Kubernetes validation)
kubectl apply --dry-run=client -f <fichier>.yaml

# snyk or yq for additional checks (selon besoin)
```

Remarques rapides : adaptez les options (configs, chemins, extensions) à votre projet.