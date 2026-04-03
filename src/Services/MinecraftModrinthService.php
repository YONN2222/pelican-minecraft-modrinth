<?php

namespace Boy132\MinecraftModrinth\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Boy132\MinecraftModrinth\Enums\MinecraftLoader;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MinecraftModrinthService
{
    public function getMinecraftVersion(Server $server): ?string
    {
        $version = $server->variables()->where(fn ($builder) => $builder->where('env_variable', 'MINECRAFT_VERSION')->orWhere('env_variable', 'MC_VERSION'))->first()?->server_value;

        if (!$version || $version === 'latest') {
            return config('pelican-minecraft-modrinth.latest_minecraft_version');
        }

        return $version;
    }

    /** @return array{hits: array<int, array<string, mixed>>, total_hits: int} */
    public function getModrinthProjects(Server $server, int $page = 1, ?string $search = null): array
    {
        $projectType = ModrinthProjectType::fromServer($server)?->value;
        $minecraftLoader = MinecraftLoader::fromServer($server)?->value;

        if (!$projectType || !$minecraftLoader) {
            return [
                'hits' => [],
                'total_hits' => 0,
            ];
        }

        $minecraftVersion = $this->getMinecraftVersion($server);

        $data = [
            'offset' => ($page - 1) * 20,
            'limit' => 20,
            'facets' => "[[\"categories:$minecraftLoader\"],[\"versions:$minecraftVersion\"],[\"project_type:{$projectType}\"]]",
        ];

        $key = "modrinth_projects:{$projectType}:$minecraftVersion:$minecraftLoader:$page";

        if ($search) {
            $data['query'] = $search;

            $key .= ":$search";
        }

        return cache()->remember($key, now()->addMinutes(30), function () use ($data) {
            try {
                return Http::asJson()
                    ->timeout(5)
                    ->connectTimeout(5)
                    ->throw()
                    ->get('https://api.modrinth.com/v2/search', $data)
                    ->json();
            } catch (Exception $exception) {
                report($exception);

                return [
                    'hits' => [],
                    'total_hits' => 0,
                ];
            }
        });
    }

    /**
     * @param  array<int, array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}>  $installedMods
     * @return array<int, array<string, mixed>>
     */
    public function getInstalledModsFromModrinth(array $installedMods, int $page = 1): array
    {
        if (empty($installedMods)) {
            return [];
        }

        $projectIds = collect($installedMods)->pluck('project_id')->unique()->values()->all();

        $perPage = 20;
        $offset = ($page - 1) * $perPage;
        $pageIds = array_slice($projectIds, $offset, $perPage);

        if (empty($pageIds)) {
            return [];
        }

        $idsParam = '["' . implode('","', $pageIds) . '"]';
        $key = 'modrinth_bulk:' . md5($idsParam);

        $modrinthProjects = cache()->remember($key, now()->addMinutes(30), function () use ($idsParam) {
            try {
                return Http::asJson()
                    ->timeout(10)
                    ->connectTimeout(5)
                    ->throw()
                    ->get('https://api.modrinth.com/v2/projects', [
                        'ids' => $idsParam,
                    ])
                    ->json();
            } catch (Exception $exception) {
                report($exception);

                return [];
            }
        });

        if (!is_array($modrinthProjects)) {
            $modrinthProjects = [];
        }

        $modrinthMap = [];
        foreach ($modrinthProjects as $project) {
            if (isset($project['id'])) {
                $modrinthMap[$project['id']] = $project;
            }
        }

        $results = [];
        foreach ($pageIds as $projectId) {
            $installedMod = null;
            foreach ($installedMods as $mod) {
                if ($mod['project_id'] === $projectId) {
                    $installedMod = $mod;
                    break;
                }
            }

            if (!$installedMod) {
                continue;
            }

            if (isset($modrinthMap[$projectId])) {
                $project = $modrinthMap[$projectId];
                $project['project_id'] = $project['id'];
                if (isset($project['updated']) && !isset($project['date_modified'])) {
                    $project['date_modified'] = $project['updated'];
                }
                if (isset($installedMod['author']) && !isset($project['author'])) {
                    $project['author'] = $installedMod['author'];
                }
                $results[] = $project;
            } else {
                $results[] = [
                    'project_id' => $installedMod['project_id'],
                    'slug' => $installedMod['project_slug'],
                    'title' => $installedMod['project_title'],
                    'description' => trans('pelican-minecraft-modrinth::strings.page.mod_unavailable'),
                    'icon_url' => null,
                    'author' => $installedMod['author'] ?? '',
                    'downloads' => 0,
                    'date_modified' => $installedMod['installed_at'],
                    'project_type' => '',
                    'unavailable' => true,
                ];
            }
        }

        return $results;
    }

    /** @return array<int, mixed> */
    public function getModrinthVersions(string $projectId, Server $server): array
    {
        $minecraftLoader = MinecraftLoader::fromServer($server)?->value;

        if (!$minecraftLoader) {
            return [];
        }

        $minecraftVersion = $this->getMinecraftVersion($server);

        $data = [
            'game_versions' => "[\"$minecraftVersion\"]",
            'loaders' => "[\"$minecraftLoader\"]",
        ];

        return cache()->remember("modrinth_versions:$projectId:$minecraftVersion:$minecraftLoader", now()->addMinutes(30), function () use ($projectId, $data) {
            try {
                $versions = Http::asJson()
                    ->timeout(5)
                    ->connectTimeout(5)
                    ->throw()
                    ->get("https://api.modrinth.com/v2/project/$projectId/version", $data)
                    ->json();

                if (!empty($versions) && is_array($versions) && isset($versions[0]['date_published'])) {
                    usort($versions, function ($a, $b) {
                        return strcmp($b['date_published'] ?? '', $a['date_published'] ?? '');
                    });
                }

                return $versions;
            } catch (Exception $exception) {
                report($exception);

                return [];
            }
        });
    }

    /**
     * @param  array<string, string>  $hashMap  [filename => sha512hash]
     * @return array<string, mixed>  [sha512hash => versionData]
     */
    public function lookupVersionsByHashes(array $hashMap): array
    {
        if (empty($hashMap)) {
            return [];
        }

        $hashes = array_values($hashMap);

        try {
            $result = Http::asJson()
                ->timeout(10)
                ->connectTimeout(5)
                ->throw()
                ->post('https://api.modrinth.com/v2/version_files', [
                    'hashes' => $hashes,
                    'algorithm' => 'sha512',
                ])
                ->json();

            return is_array($result) ? $result : [];
        } catch (Exception $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * @param  array<string>  $projectIds
     * @return array<string, mixed>  [projectId => projectData]
     *
     * @throws Exception
     */
    protected function fetchProjectsByIds(array $projectIds): array
    {
        if (empty($projectIds)) {
            return [];
        }

        $projectIds = array_values(array_unique($projectIds));
        $idsParam = '["' . implode('","', $projectIds) . '"]';

        try {
            $projects = Http::asJson()
                ->timeout(10)
                ->connectTimeout(5)
                ->throw()
                ->get('https://api.modrinth.com/v2/projects', [
                    'ids' => $idsParam,
                ])
                ->json();

            if (!is_array($projects)) {
                return [];
            }

            $map = [];
            foreach ($projects as $project) {
                if (isset($project['id'])) {
                    $map[$project['id']] = $project;
                }
            }

            return $map;
        } catch (Exception $exception) {
            report($exception);

            throw new Exception('Modrinth projects lookup failed', previous: $exception);
        }
    }

    protected function resolveProjectAuthor(?array $project, array $versionData): ?string
    {
        if (is_string($project['author'] ?? null) && $project['author'] !== '') {
            return $project['author'];
        }

        if (is_string($project['team'] ?? null) && $project['team'] !== '') {
            $teamUsername = $this->fetchTeamPrimaryUsername($project['team']);
            if ($teamUsername !== null) {
                return $teamUsername;
            }
        }

        if (is_string($versionData['author_id'] ?? null) && $versionData['author_id'] !== '') {
            return $this->fetchUsernameByUserId($versionData['author_id']);
        }

        return null;
    }

    protected function fetchTeamPrimaryUsername(string $teamId): ?string
    {
        $cacheKey = 'modrinth_team_primary_user:' . $teamId;

        return cache()->remember($cacheKey, now()->addMinutes(30), function () use ($teamId) {
            try {
                $members = Http::asJson()
                    ->timeout(10)
                    ->connectTimeout(5)
                    ->throw()
                    ->get("https://api.modrinth.com/v2/team/{$teamId}/members")
                    ->json();

                if (!is_array($members) || empty($members)) {
                    return null;
                }

                foreach ($members as $member) {
                    $username = $member['user']['username'] ?? null;
                    if (is_string($username) && $username !== '') {
                        return $username;
                    }
                }

                return null;
            } catch (Exception $exception) {
                report($exception);

                return null;
            }
        });
    }

    protected function fetchUsernameByUserId(string $userId): ?string
    {
        $cacheKey = 'modrinth_user_username:' . $userId;

        return cache()->remember($cacheKey, now()->addMinutes(30), function () use ($userId) {
            try {
                $user = Http::asJson()
                    ->timeout(10)
                    ->connectTimeout(5)
                    ->throw()
                    ->get("https://api.modrinth.com/v2/user/{$userId}")
                    ->json();

                $username = $user['username'] ?? null;

                return is_string($username) && $username !== '' ? $username : null;
            } catch (Exception $exception) {
                report($exception);

                return null;
            }
        });
    }

    /**
     * Scans the mods/plugins folder, hashes unknown JARs, looks them up on Modrinth,
     * imports matches into metadata, and returns filenames not found on Modrinth.
     *
     * @return array<string>  Filenames with no Modrinth match
     *
     * @throws Exception
     */
    public function scanAndImportMods(Server $server, DaemonFileRepository $fileRepository): array
    {
        $cacheKey = "modrinth_hash_scan:{$server->id}";

        return cache()->remember($cacheKey, now()->addMinutes(10), function () use ($server, $fileRepository) {
            return $this->performScan($server, $fileRepository);
        });
    }

    /**
     * @return array<string>  Filenames with no Modrinth match
     *
     * @throws Exception
     */
    protected function performScan(Server $server, DaemonFileRepository $fileRepository): array
    {
        $type = ModrinthProjectType::fromServer($server);

        if (!$type) {
            return [];
        }

        try {
            $directoryContents = $fileRepository->setServer($server)->getDirectory($type->getFolder());
        } catch (Exception $exception) {
            report($exception);

            return [];
        }

        if (!is_array($directoryContents) || isset($directoryContents['error'])) {
            return [];
        }

        $jarFiles = collect($directoryContents)
            ->filter(fn ($item) => is_array($item) && isset($item['name']) && str($item['name'])->lower()->endsWith('.jar'))
            ->pluck('name')
            ->values()
            ->toArray();

        if (empty($jarFiles)) {
            return [];
        }

        $installedModsMetadata = $this->getInstalledModsMetadata($server, $fileRepository);
        $knownFilenames = [];
        foreach ($installedModsMetadata as $installedMod) {
            $knownFilenames[strtolower($installedMod['filename'])] = true;
        }

        $unknownFiles = array_values(
            array_filter($jarFiles, function ($name) use ($knownFilenames) {
                $normalizedName = strtolower($name);

                return !isset($knownFilenames[$normalizedName]);
            })
        );

        if (empty($unknownFiles)) {
            return [];
        }

        $folder = $type->getFolder();
        $hashMap = []; // [filename => sha512hash]

        foreach ($unknownFiles as $filename) {
            try {
                $content = $fileRepository->setServer($server)->getContent("{$folder}/{$filename}");
                $hashMap[$filename] = hash('sha512', $content);
            } catch (Exception $exception) {
                report($exception);
            }
        }

        if (empty($hashMap)) {
            return $unknownFiles;
        }

        $versionsByHash = $this->lookupVersionsByHashes($hashMap);

        if (empty($versionsByHash)) {
            return $unknownFiles;
        }

        $hashToFilenames = [];
        foreach ($hashMap as $filename => $hash) {
            if (!isset($hashToFilenames[$hash])) {
                $hashToFilenames[$hash] = [];
            }

            $hashToFilenames[$hash][] = $filename;
        }

        $matchedVersions = []; // [filename => versionData]
        $projectIds = [];

        foreach ($versionsByHash as $hash => $versionData) {
            if (!isset($hashToFilenames[$hash]) || !is_array($versionData) || !isset($versionData['project_id'])) {
                continue;
            }

            foreach ($hashToFilenames[$hash] as $filename) {
                $matchedVersions[$filename] = $versionData;
            }

            $projectIds[] = $versionData['project_id'];
        }

        if (empty($matchedVersions)) {
            return $unknownFiles;
        }

        try {
            $projectsMap = $this->fetchProjectsByIds(array_unique($projectIds));
        } catch (Exception $exception) {
            report($exception);
            $projectsMap = [];
        }

        $matchedFilenames = [];

        foreach ($matchedVersions as $filename => $versionData) {
            if (!isset($versionData['project_id'], $versionData['id'], $versionData['version_number'])) {
                continue;
            }

            $projectId = $versionData['project_id'];
            $project = $projectsMap[$projectId] ?? null;

            $saved = $this->saveModMetadata(
                server: $server,
                fileRepository: $fileRepository,
                projectId: $projectId,
                projectSlug: $project['slug'] ?? $projectId,
                projectTitle: $project['title'] ?? $projectId,
                versionId: $versionData['id'],
                versionNumber: $versionData['version_number'],
                filename: $filename,
                author: $this->resolveProjectAuthor($project, $versionData)
            );

            if ($saved) {
                $matchedFilenames[] = $filename;
            }
        }

        return array_values(
            array_filter($unknownFiles, fn ($name) => !in_array($name, $matchedFilenames, true))
        );
    }

    /**
     * @throws Exception
     */
    protected function getMetadataFilePath(Server $server): string
    {
        $type = ModrinthProjectType::fromServer($server);

        if (!$type) {
            throw new Exception("Server {$server->id} does not support Modrinth mods or plugins");
        }

        return $type->getFolder() . '/.modrinth-metadata.json';
    }

    /** @return array<int, array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}> */
    public function getInstalledModsMetadata(Server $server, DaemonFileRepository $fileRepository): array
    {
        try {
            $metadataPath = $this->getMetadataFilePath($server);
            $content = $fileRepository->setServer($server)->getContent($metadataPath);
            $metadata = json_decode($content, true);

            if (!is_array($metadata) || !isset($metadata['installed_mods']) || !is_array($metadata['installed_mods'])) {
                return [];
            }

            $validInstalledMods = [];
            $requiredKeys = [
                'project_id',
                'project_slug',
                'project_title',
                'version_id',
                'version_number',
                'filename',
                'installed_at',
            ];

            $requiredKeysFlipped = array_flip($requiredKeys);

            foreach ($metadata['installed_mods'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $missingKeys = array_diff_key($requiredKeysFlipped, $entry);
                if (empty($missingKeys)) {
                    $validInstalledMods[] = $entry;
                }
            }

            return $validInstalledMods;
        } catch (Exception $exception) {
            return [];
        }
    }

    public function saveModMetadata(
        Server $server,
        DaemonFileRepository $fileRepository,
        string $projectId,
        string $projectSlug,
        string $projectTitle,
        string $versionId,
        string $versionNumber,
        string $filename,
        ?string $author = null
    ): bool {
        try {
            return Cache::lock("modrinth_metadata:{$server->id}", 10)->block(5, function () use ($server, $fileRepository, $projectId, $projectSlug, $projectTitle, $versionId, $versionNumber, $filename, $author) {
                $metadata = [
                    'installed_mods' => $this->getInstalledModsMetadata($server, $fileRepository),
                ];

                $metadata['installed_mods'] = collect($metadata['installed_mods'])
                    ->filter(fn ($mod) => $mod['project_id'] !== $projectId && strtolower($mod['filename']) !== strtolower($filename))
                    ->values()
                    ->toArray();

                $modEntry = [
                    'project_id' => $projectId,
                    'project_slug' => $projectSlug,
                    'project_title' => $projectTitle,
                    'version_id' => $versionId,
                    'version_number' => $versionNumber,
                    'filename' => $filename,
                    'installed_at' => now()->toIso8601String(),
                ];

                if ($author !== null) {
                    $modEntry['author'] = $author;
                }

                $metadata['installed_mods'][] = $modEntry;

                $metadataPath = $this->getMetadataFilePath($server);
                $response = $fileRepository->setServer($server)->putContent(
                    $metadataPath,
                    json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                );

                return !$response->failed();
            }) === true;
        } catch (Exception $exception) {
            report($exception);

            return false;
        }
    }

    public function removeModMetadata(Server $server, DaemonFileRepository $fileRepository, string $projectId): bool
    {
        try {
            return Cache::lock("modrinth_metadata:{$server->id}", 10)->block(5, function () use ($server, $fileRepository, $projectId) {
                $metadata = [
                    'installed_mods' => $this->getInstalledModsMetadata($server, $fileRepository),
                ];

                $metadata['installed_mods'] = collect($metadata['installed_mods'])
                    ->filter(fn ($mod) => $mod['project_id'] !== $projectId)
                    ->values()
                    ->toArray();

                $metadataPath = $this->getMetadataFilePath($server);
                $response = $fileRepository->setServer($server)->putContent(
                    $metadataPath,
                    json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                );

                return !$response->failed();
            }) === true;
        } catch (Exception $exception) {
            report($exception);

            return false;
        }
    }

    /** @return array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}|null */
    public function getInstalledMod(Server $server, DaemonFileRepository $fileRepository, string $projectId): ?array
    {
        $installedMods = $this->getInstalledModsMetadata($server, $fileRepository);

        foreach ($installedMods as $mod) {
            if ($mod['project_id'] === $projectId) {
                return $mod;
            }
        }

        return null;
    }

    /**
     * @param  array{version_id: string, version_number: string}  $installedMod
     * @param  array<int, array{id: string, version_number: string}>  $availableVersions
     */
    public function isUpdateAvailable(array $installedMod, array $availableVersions): bool
    {
        if (empty($availableVersions)) {
            return false;
        }

        $latestVersion = $availableVersions[0];

        return $installedMod['version_id'] !== $latestVersion['id'];
    }

    /**
     * @return array<string>
     */
    public function getInstalledMods(Server $server, DaemonFileRepository $fileRepository): array
    {
        $metadata = $this->getInstalledModsMetadata($server, $fileRepository);

        return collect($metadata)
            ->pluck('filename')
            ->map(fn ($name) => strtolower($name))
            ->toArray();
    }
}
