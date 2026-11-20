<?php

declare(strict_types=1);

/*
 * API reference configuration for Doctum (https://github.com/code-lts/doctum).
 *
 * Doctum is not a Composer dependency. Download its PHAR and run, from the repository root:
 *
 *     curl -sSLO https://doctum.long-term.support/releases/latest/doctum.phar
 *     php doctum.phar update doctum.php
 *
 * The HTML reference is written to build/api/<version>/ (gitignored).
 * Classes marked @internal are part of the reference but are not public API.
 */

use Doctum\Doctum;
use Doctum\RemoteRepository\GitHubRemoteRepository;
use Doctum\Version\GitVersionCollection;
use Symfony\Component\Finder\Finder;

$source = __DIR__ . '/src';

$files = Finder::create()
    ->files()
    ->name('*.php')
    ->in($source);

$versions = GitVersionCollection::create(__DIR__)
    ->addFromTags('v*')
    ->add('master', 'master (stable)')
    ->add('develop', 'develop (next)');

return new Doctum($files, [
    'title' => 'Pey PHP SDK (unofficial): API reference',
    'versions' => $versions,
    'build_dir' => __DIR__ . '/build/api/%version%',
    'cache_dir' => __DIR__ . '/build/api-cache/%version%',
    'remote_repository' => new GitHubRemoteRepository('DEV1-Softworks/pey-php-sdk', __DIR__),
    'default_opened_level' => 2,
]);
