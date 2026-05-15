ajouter un tool "lint" qui prend en paramètre "language" et "file(s)".

selon l'extension du fichier, on détermine le language et le linter.

Si le host n'a pas l'outil accesible, on retourne une erreur.

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