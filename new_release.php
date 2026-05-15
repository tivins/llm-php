<?php

declare(strict_types=1);

// Get the current version
$composer = json_decode(file_get_contents('composer.json'), true, 512, JSON_THROW_ON_ERROR);
$version = $composer['version'];
echo "Current version: $version\n";

$gitVersion = "v$version";

// Get the latest tag
$lastVersion = "";
try {
    $cmd = 'git for-each-ref --sort=-creatordate --count=1 refs/tags';
    $latestTag = shell_exec($cmd);
    $words = preg_split('/\s+/', $latestTag);
    if (count($words) < 3) {
        throw new Exception('Latest tag is not a valid tag');
    }
    if (strlen($words[0]) !== 40) {
        throw new Exception('Latest tag is not a SHA-1');
    }
    if ($words[1] !== 'tag') {
        throw new Exception('Latest tag is not a tag');
    }
    if ($words[2] === 'refs/tags/' . $gitVersion) {
        throw new Exception('Latest tag is already the release tag');
    }
    $lastVersion = substr($words[2], strlen('refs/tags/'));
}
catch (Exception $e) {
    echo "Getting latest tag failed: {$e->getMessage()}\n";
    exit(1);
}

$lastVersionNumbers = ltrim($lastVersion, 'v');
echo "Creating tag {$lastVersion} ($lastVersionNumbers) -> {$gitVersion} ($version)\n";

$releaseNotes = 'Release v'.$version;
/*.'

**Full Changelog**: https://github.com/tivins/llm-php/compare/v'.$lastVersionNumbers.'...v'.$version.'';

echo "Release notes: {$releaseNotes}\n";
*/

/*
try {
    $changelogPath = __DIR__ . '/CHANGELOG.md';
    $changelog = file_get_contents($changelogPath);
    if ($changelog === false) {
        throw new Exception('Changelog not found or unreadable');
    }

    // $version (composer) is the release being cut; $lastVersionNumbers is the newest
    // existing git tag without "v". CHANGELOG sections are newest-first (## x.y.z — date).
    // Collect every section with x.y.z > $lastVersionNumbers and x.y.z <= $version.
    $lines = explode("\n", $changelog);
    $sections = [];
    $currentVersion = null;
    $currentLines = [];

    foreach ($lines as $line) {
        if (preg_match('/^## (\d+\.\d+\.\d+)\s*[—\-]\s*.+$/u', $line, $m)) {
            if ($currentVersion !== null) {
                $sections[] = ['version' => $currentVersion, 'lines' => $currentLines];
            }
            $currentVersion = $m[1];
            $currentLines = [$line];
        } elseif ($currentVersion !== null) {
            $currentLines[] = $line;
        }
    }
    if ($currentVersion !== null) {
        $sections[] = ['version' => $currentVersion, 'lines' => $currentLines];
    }

    $chunks = [];
    foreach ($sections as $sec) {
        $v = $sec['version'];
        if (version_compare($v, $version, '>')) {
            continue;
        }
        if (version_compare($v, $lastVersionNumbers, '<=')) {
            break;
        }
        $chunks[] = trim(implode("\n", $sec['lines']));
    }

    $releaseNotes = trim(implode("\n\n", array_filter($chunks, static fn (string $s): bool => $s !== '')));
    if ($releaseNotes === '') {
        throw new Exception(
            "No changelog entries between v{$lastVersionNumbers} and v{$version}; update CHANGELOG.md"
        );
    }

    echo "Release notes: {$releaseNotes}\n";
}
catch (Exception $e) {
    echo "Getting release notes failed: {$e->getMessage()}\n";
    exit(1);
}
*/

// Create a new tag
$cmd = 'git tag -a '.escapeshellarg($gitVersion).' -m '.escapeshellarg($releaseNotes);
echo $cmd . "\n";
exec($cmd);

// Push the tag
$cmd = "git push origin ".escapeshellarg($gitVersion);
echo $cmd . "\n";
exec($cmd);
