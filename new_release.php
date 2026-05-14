<?php

declare(strict_types=1);

$composer = json_decode(file_get_contents('composer.json'), true);
$version = $composer['version'];
echo $version . "\n";

$gitVersion = "v$version";

// Create a new tag
$cmd = 'git tag -a '.escapeshellarg($gitVersion).' -m '.escapeshellarg('Release '.$gitVersion);
echo $cmd . "\n";
exec($cmd);

// Push the tag
$cmd = "git push origin ".escapeshellarg($gitVersion);
echo $cmd . "\n";
exec($cmd);
