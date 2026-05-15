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

echo "Creating tag {$lastVersion} -> {$gitVersion}\n";

die("Temporary stop\n\n");

// Create a new tag
$cmd = 'git tag -a '.escapeshellarg($gitVersion).' -m '.escapeshellarg('Release '.$gitVersion);
echo $cmd . "\n";
exec($cmd);

// Push the tag
$cmd = "git push origin ".escapeshellarg($gitVersion);
echo $cmd . "\n";
exec($cmd);
