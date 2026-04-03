<?php

namespace Boy132\MinecraftModrinth\Filament\Server\Pages;

use App\Filament\Server\Resources\Files\Pages\ListFiles;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Traits\Filament\BlockAccessInConflict;
use Boy132\MinecraftModrinth\Enums\MinecraftLoader;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
use Boy132\MinecraftModrinth\Facades\MinecraftModrinth;
use Exception;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MinecraftModrinthProjectPage extends Page implements HasTable
{
    use BlockAccessInConflict;
    use HasTabs;
    use InteractsWithTable;

    /** @var array<int, array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}>|null */
    protected ?array $installedModsMetadata = null;

    /** @var array<string, array<int, mixed>> Cache for version data by project_id */
    protected array $versionsCache = [];

    /** @var array<string> */
    public array $unknownFiles = [];

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-packages';

    protected static ?string $slug = 'modrinth';

    protected static ?int $navigationSort = 30;

    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return parent::canAccess() && ModrinthProjectType::fromServer($server);
    }

    public static function getNavigationLabel(): string
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        $type = ModrinthProjectType::fromServer($server);

        return $type?->getLabel() ?? 'Modrinth';
    }

    public static function getModelLabel(): string
    {
        return static::getNavigationLabel();
    }

    public static function getPluralModelLabel(): string
    {
        return static::getNavigationLabel();
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        $this->loadDefaultActiveTab();
    }

    public function updatedActiveTab(?string $activeTab): void
    {
        if ($activeTab !== 'installed') {
            return;
        }

        /** @var Server $server */
        $server = Filament::getTenant();

        $scanCacheKey = "modrinth_hash_scan:{$server->id}";
        if (Cache::has($scanCacheKey)) {
            return;
        }

        /** @var DaemonFileRepository $fileRepository */
        $fileRepository = app(DaemonFileRepository::class);
        if (!$this->hasUnknownJarCandidates($server, $fileRepository)) {
            return;
        }

        $projectType = ModrinthProjectType::fromServer($server);
        $scanInProgressKey = match ($projectType) {
            ModrinthProjectType::Plugin => 'pelican-minecraft-modrinth::strings.notifications.scan_in_progress_plugins',
            default => 'pelican-minecraft-modrinth::strings.notifications.scan_in_progress_mods',
        };

        Notification::make()
            ->title(trans($scanInProgressKey))
            ->info()
            ->send();
    }

    protected function hasUnknownJarCandidates(Server $server, DaemonFileRepository $fileRepository): bool
    {
        $type = ModrinthProjectType::fromServer($server);
        if (!$type) {
            return false;
        }

        try {
            $directoryContents = $fileRepository->setServer($server)->getDirectory($type->getFolder());
        } catch (Exception $exception) {
            report($exception);

            return false;
        }

        $jarFiles = collect($directoryContents)
            ->filter(fn ($item) => isset($item['name']) && str_ends_with(strtolower($item['name']), '.jar'))
            ->pluck('name')
            ->values()
            ->toArray();

        if (empty($jarFiles)) {
            return false;
        }

        $knownFilenames = array_map('strtolower', MinecraftModrinth::getInstalledMods($server, $fileRepository));

        foreach ($jarFiles as $filename) {
            if (!in_array(strtolower($filename), $knownFilenames, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(trans('pelican-minecraft-modrinth::strings.page.view_all')),
            'installed' => Tab::make(trans('pelican-minecraft-modrinth::strings.page.view_installed')),
        ];
    }

    /** @return array<int, array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string}> */
    protected function getInstalledModsMetadata(): array
    {
        if ($this->installedModsMetadata === null) {
            /** @var Server $server */
            $server = Filament::getTenant();
            /** @var DaemonFileRepository $fileRepository */
            $fileRepository = app(DaemonFileRepository::class);

            $this->installedModsMetadata = MinecraftModrinth::getInstalledModsMetadata($server, $fileRepository);
        }

        return $this->installedModsMetadata;
    }

    /** @return array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}|null */
    protected function getInstalledMod(string $projectId): ?array
    {
        $installedMods = $this->getInstalledModsMetadata();

        foreach ($installedMods as $mod) {
            if ($mod['project_id'] === $projectId) {
                return $mod;
            }
        }

        return null;
    }

    /** @return array<int, mixed> */
    protected function getCachedVersions(string $projectId): array
    {
        if (!isset($this->versionsCache[$projectId])) {
            /** @var Server $server */
            $server = Filament::getTenant();
            $this->versionsCache[$projectId] = MinecraftModrinth::getModrinthVersions($projectId, $server);
        }

        return $this->versionsCache[$projectId];
    }

    /**
     * @param  array<int, array{primary: bool, filename: string, url: string}>  $files
     * @return array{primary: bool, filename: string, url: string}|null
     */
    protected function getPrimaryFile(array $files): ?array
    {
        foreach ($files as $file) {
            if (!empty($file['primary'])) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @throws Exception
     */
    protected function validateFilename(string $filename): string
    {
        if ($filename === '' || $filename === '.' || str_contains($filename, "\0") || str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            throw new Exception('Invalid filename: potential path traversal detected');
        }

        return basename($filename);
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $versionData
     * @param array<string, mixed> $primaryFile
     * @param array<string, mixed>|null $installedMod
     *
     * @throws Exception
     */
    private function performInstallOrUpdate(
        Server $server,
        DaemonFileRepository $fileRepository,
        array $record,
        array $versionData,
        array $primaryFile,
        ?array $installedMod = null
    ): void {
        $safeNewFilename = $this->validateFilename($primaryFile['filename']);
        $oldFilename = $installedMod ? $this->validateFilename($installedMod['filename']) : null;

        $type = ModrinthProjectType::fromServer($server);
        if (!$type) {
            throw new Exception('Server does not support Modrinth mods or plugins');
        }

        $folder = $type->getFolder();

        $fileRepository->setServer($server)->pull($primaryFile['url'], $folder);

        $saved = MinecraftModrinth::saveModMetadata(
            $server,
            $fileRepository,
            $record['project_id'],
            $record['slug'],
            $record['title'],
            $versionData['id'],
            $versionData['version_number'],
            $safeNewFilename,
            $record['author'] ?? null
        );

        if (!$saved) {
            try {
                Http::daemon($server->node)
                    ->post("/api/servers/{$server->uuid}/files/delete", [
                        'root' => '/',
                        'files' => [$folder . '/' . $safeNewFilename],
                    ])
                    ->throw();
            } catch (Exception $rollbackException) {
                report($rollbackException);
            }

            throw new Exception('Failed to save mod metadata');
        }

        if ($oldFilename && $oldFilename !== $safeNewFilename) {
            try {
                Http::daemon($server->node)
                    ->post("/api/servers/{$server->uuid}/files/delete", [
                        'root' => '/',
                        'files' => [$folder . '/' . $oldFilename],
                    ])
                    ->throw();
            } catch (Exception $deleteException) {
                try {
                    Http::daemon($server->node)
                        ->post("/api/servers/{$server->uuid}/files/delete", [
                            'root' => '/',
                            'files' => [$folder . '/' . $safeNewFilename],
                        ])
                        ->throw();
                } catch (Exception $rollbackException) {
                    report($rollbackException);
                }

                if ($installedMod && !MinecraftModrinth::saveModMetadata(
                    $server,
                    $fileRepository,
                    $record['project_id'],
                    $installedMod['project_slug'],
                    $installedMod['project_title'],
                    $installedMod['version_id'],
                    $installedMod['version_number'],
                    $oldFilename,
                    $installedMod['author'] ?? null
                )) {
                    report(new Exception('Failed to restore old mod metadata during rollback'));
                }

                throw $deleteException;
            }
        }
    }

    /**
     * @return array{updated: int, failed: int}
     */
    private function performBulkUpdate(Server $server, DaemonFileRepository $fileRepository): array
    {
        $updatedCount = 0;
        $failedCount = 0;
        $installedMods = $this->getInstalledModsMetadata();

        foreach ($installedMods as $installedMod) {
            try {
                if (!isset($installedMod['project_id'], $installedMod['project_slug'], $installedMod['project_title'], $installedMod['version_id'])) {
                    continue;
                }

                $versions = MinecraftModrinth::getModrinthVersions($installedMod['project_id'], $server);
                if (empty($versions) || !isset($versions[0]['id'], $versions[0]['version_number'], $versions[0]['files'])) {
                    continue;
                }

                $latestVersion = $versions[0];
                if ($installedMod['version_id'] === $latestVersion['id']) {
                    continue;
                }

                $primaryFile = $this->getPrimaryFile($latestVersion['files']);
                if (!$primaryFile) {
                    throw new Exception('No downloadable file found for bulk update');
                }

                $record = [
                    'project_id' => $installedMod['project_id'],
                    'slug' => $installedMod['project_slug'],
                    'title' => $installedMod['project_title'],
                    'author' => $installedMod['author'] ?? null,
                ];

                $this->performInstallOrUpdate($server, $fileRepository, $record, $latestVersion, $primaryFile, $installedMod);
                $updatedCount++;
            } catch (Exception $exception) {
                report($exception);
                $failedCount++;
            }
        }

        if ($updatedCount > 0 || $failedCount > 0) {
            $this->installedModsMetadata = null;
            $this->versionsCache = [];
        }

        return [
            'updated' => $updatedCount,
            'failed' => $failedCount,
        ];
    }

    /**
     * @throws Exception
     */
    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $search, int $page) {
                /** @var Server $server */
                $server = Filament::getTenant();

                if ($this->activeTab === 'installed') {
                    $perPage = 20;
                    $installedMods = $this->getInstalledModsMetadata();
                    $unknownFiles = [];

                    /** @var DaemonFileRepository $fileRepository */
                    $fileRepository = app(DaemonFileRepository::class);

                    $metadataBefore = count($installedMods);

                    try {
                        $this->unknownFiles = MinecraftModrinth::scanAndImportMods($server, $fileRepository);
                        $unknownFiles = $this->unknownFiles;

                        $this->installedModsMetadata = null;
                        $installedMods = $this->getInstalledModsMetadata();

                        $importedCount = max(0, count($installedMods) - $metadataBefore);
                        if ($importedCount > 0) {
                            Notification::make()
                                ->title(trans('pelican-minecraft-modrinth::strings.notifications.scan_success', ['count' => $importedCount]))
                                ->success()
                                ->send();
                        }
                    } catch (Exception $exception) {
                        report($exception);

                        $message = strtolower($exception->getMessage());
                        if (str_contains($message, 'modrinth')) {
                            Notification::make()
                                ->title(trans('pelican-minecraft-modrinth::strings.notifications.scan_failed'))
                                ->danger()
                                ->send();
                        }

                        $this->unknownFiles = [];
                    }

                    if ($search) {
                        $searchLower = strtolower($search);
                        $installedMods = array_values(array_filter($installedMods, function (array $mod) use ($searchLower) {
                            return str_contains(strtolower($mod['project_title']), $searchLower)
                                || str_contains(strtolower($mod['project_slug']), $searchLower);
                        }));

                        $unknownFiles = array_values(array_filter($unknownFiles, fn (string $filename) => str_contains(strtolower($filename), $searchLower)));
                    }

                    $projects = [];
                    if (!empty($installedMods)) {
                        $installedPages = (int) ceil(count($installedMods) / $perPage);
                        for ($installedPage = 1; $installedPage <= $installedPages; $installedPage++) {
                            $projects = array_merge($projects, MinecraftModrinth::getInstalledModsFromModrinth($installedMods, $installedPage));
                        }
                    }

                    foreach ($unknownFiles as $filename) {
                        $projects[] = [
                            'project_id' => null,
                            'slug' => null,
                            'title' => $filename,
                            'description' => null,
                            'icon_url' => null,
                            'author' => null,
                            'downloads' => null,
                            'date_modified' => null,
                            'not_on_modrinth' => true,
                        ];
                    }

                    $totalCount = count($installedMods) + count($unknownFiles);
                    $offset = ($page - 1) * $perPage;
                    $pagedProjects = array_slice($projects, $offset, $perPage);

                    return new LengthAwarePaginator($pagedProjects, $totalCount, $perPage, $page);
                } else {
                    $response = MinecraftModrinth::getModrinthProjects($server, $page, $search);

                    return new LengthAwarePaginator($response['hits'], $response['total_hits'], 20, $page);
                }
            })
            ->paginated([20])
            ->columns([
                ImageColumn::make('icon_url')
                    ->label(''),
                TextColumn::make('title')
                    ->searchable()
                    ->description(function (array $record): ?string {
                        if ($record['not_on_modrinth'] ?? false) {
                            return trans('pelican-minecraft-modrinth::strings.badges.not_on_modrinth');
                        }

                        $description = $record['description'] ?? null;
                        if (!is_string($description)) {
                            return null;
                        }

                        return (strlen($description) > 120) ? substr($description, 0, 120) . '...' : $description;
                    }),
                TextColumn::make('author')
                    ->url(fn ($state) => "https://modrinth.com/user/$state", true)
                    ->toggleable(),
                TextColumn::make('downloads')
                    ->icon('tabler-download')
                    ->numeric()
                    ->toggleable(),
                TextColumn::make('date_modified')
                    ->icon('tabler-calendar')
                    ->formatStateUsing(fn ($state) => $state ? Carbon::parse($state, 'UTC')->diffForHumans() : '')
                    ->tooltip(fn ($state) => $state ? Carbon::parse($state, 'UTC')->timezone(user()->timezone ?? 'UTC')->format($table->getDefaultDateTimeDisplayFormat()) : '')
                    ->toggleable(),
            ])
            ->recordUrl(function (array $record) {
                if (!empty($record['unavailable']) || ($record['not_on_modrinth'] ?? false)) {
                    return null;
                }

                return "https://modrinth.com/{$record['project_type']}/{$record['slug']}";
            }, true)
            ->recordActions([
                Action::make('versions')
                    ->iconButton()
                    ->icon('tabler-list')
                    ->color('info')
                    ->tooltip(trans('pelican-minecraft-modrinth::strings.actions.versions'))
                    ->hidden(fn (array $record): bool => $record['not_on_modrinth'] ?? false)
                    ->modalSubmitAction(false)
                    ->schema(function (array $record) {
                        $versions = $this->getCachedVersions($record['project_id']);

                        $installedMod = $this->getInstalledMod($record['project_id']);
                        $installedVersionId = $installedMod['version_id'] ?? null;

                        $sections = [];
                        foreach ($versions as $versionIndex => $versionData) {
                            $primaryFile = $this->getPrimaryFile($versionData['files'] ?? []);

                            $sectionComponents = [
                                TextEntry::make('type_' . $versionIndex)
                                    ->label(trans('pelican-minecraft-modrinth::strings.version.type'))
                                    ->state($versionData['version_type'] ?? '')
                                    ->badge()
                                    ->color(match ($versionData['version_type'] ?? '') {
                                        'release' => 'success',
                                        'beta' => 'warning',
                                        'alpha' => 'danger',
                                        default => 'gray',
                                    }),
                                TextEntry::make('downloads_' . $versionIndex)
                                    ->label(trans('pelican-minecraft-modrinth::strings.version.downloads'))
                                    ->state($versionData['downloads'] ?? 0)
                                    ->icon('tabler-download')
                                    ->numeric(),
                                TextEntry::make('published_' . $versionIndex)
                                    ->label(trans('pelican-minecraft-modrinth::strings.version.published'))
                                    ->state(fn () => isset($versionData['date_published']) ? Carbon::parse($versionData['date_published'], 'UTC')->diffForHumans() : ''),
                            ];

                            if (!empty($versionData['changelog'])) {
                                $sectionComponents[] = TextEntry::make('changelog_' . $versionIndex)
                                    ->label(trans('pelican-minecraft-modrinth::strings.version.changelog'))
                                    ->state($versionData['changelog'])
                                    ->markdown();
                            }

                            if (($versionData['id'] ?? null) === $installedVersionId) {
                                $headerAction = Action::make('installed_' . $versionIndex)
                                    ->label(trans('pelican-minecraft-modrinth::strings.actions.installed'))
                                    ->icon('tabler-check')
                                    ->color('success')
                                    ->disabled();
                                $sectionIcon = 'tabler-check';
                                $sectionIconColor = 'success';
                            } else {
                                $headerAction = Action::make('install_version_' . $versionIndex)
                                    ->label(trans('pelican-minecraft-modrinth::strings.actions.install'))
                                    ->icon('tabler-download')
                                    ->action(function (DaemonFileRepository $fileRepository) use ($record, $versionData, $primaryFile) {
                                        try {
                                            /** @var Server $server */
                                            $server = Filament::getTenant();

                                            if (!isset($versionData['id'], $versionData['version_number'], $versionData['files'])) {
                                                throw new Exception('Invalid version data structure');
                                            }

                                            if (!$primaryFile) {
                                                throw new Exception('No downloadable file found');
                                            }

                                            $installedMod = $this->getInstalledMod($record['project_id']);

                                            $this->performInstallOrUpdate($server, $fileRepository, $record, $versionData, $primaryFile, $installedMod);

                                            $this->installedModsMetadata = null;
                                            $this->versionsCache = [];
                                            $this->js('$wire.$refresh()');

                                            Notification::make()
                                                ->title(trans('pelican-minecraft-modrinth::strings.notifications.install_success'))
                                                ->body(trans('pelican-minecraft-modrinth::strings.notifications.install_success_body', [
                                                    'name' => $record['title'],
                                                    'version' => $versionData['version_number'],
                                                ]))
                                                ->success()
                                                ->send();
                                        } catch (Exception $exception) {
                                            report($exception);

                                            $this->installedModsMetadata = null;
                                            $this->versionsCache = [];
                                            $this->js('$wire.$refresh()');

                                            Notification::make()
                                                ->title(trans('pelican-minecraft-modrinth::strings.notifications.install_failed'))
                                                ->body(trans('pelican-minecraft-modrinth::strings.notifications.install_failed_body'))
                                                ->danger()
                                                ->send();
                                        }
                                    });
                                $sectionIcon = null;
                                $sectionIconColor = null;
                            }

                            $section = Section::make($versionData['version_number'] ?? '')
                                ->headerActions([$headerAction])
                                ->schema($sectionComponents)
                                ->collapsible()
                                ->collapsed(!($versionData['featured'] ?? false));

                            if ($sectionIcon !== null) {
                                $section = $section->icon($sectionIcon)->iconColor($sectionIconColor);
                            }

                            $sections[] = $section;
                        }

                        return $sections;
                    }),
                Action::make('install_latest')
                    ->iconButton()
                    ->icon('tabler-download')
                    ->color('success')
                    ->tooltip(trans('pelican-minecraft-modrinth::strings.actions.install_latest'))
                    ->hidden(fn (array $record): bool => $record['not_on_modrinth'] ?? false)
                    ->visible(function (array $record) {
                        if (empty($record['project_id'])) {
                            return false;
                        }

                        $installedMod = $this->getInstalledMod($record['project_id']);

                        return is_null($installedMod);
                    })
                    ->action(function (array $record, DaemonFileRepository $fileRepository) {
                        try {
                            /** @var Server $server */
                            $server = Filament::getTenant();

                            $versions = MinecraftModrinth::getModrinthVersions($record['project_id'], $server);

                            if (empty($versions)) {
                                throw new Exception('No compatible versions found');
                            }

                            $latestVersion = $versions[0];

                            if (!isset($latestVersion['id'], $latestVersion['version_number'], $latestVersion['files'])) {
                                throw new Exception('Invalid version data structure');
                            }

                            $primaryFile = $this->getPrimaryFile($latestVersion['files']);

                            if (!$primaryFile) {
                                throw new Exception('No downloadable file found');
                            }

                            $this->performInstallOrUpdate($server, $fileRepository, $record, $latestVersion, $primaryFile);

                            $this->installedModsMetadata = null;
                            $this->versionsCache = [];

                            Notification::make()
                                ->title(trans('pelican-minecraft-modrinth::strings.notifications.install_success'))
                                ->body(trans('pelican-minecraft-modrinth::strings.notifications.install_success_body', [
                                    'name' => $record['title'],
                                    'version' => $latestVersion['version_number'],
                                ]))
                                ->success()
                                ->send();
                        } catch (Exception $exception) {
                            report($exception);

                            $this->installedModsMetadata = null;
                            $this->versionsCache = [];

                            Notification::make()
                                ->title(trans('pelican-minecraft-modrinth::strings.notifications.install_failed'))
                                ->body(trans('pelican-minecraft-modrinth::strings.notifications.install_failed_body'))
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('update')
                    ->iconButton()
                    ->icon('tabler-refresh')
                    ->color('warning')
                    ->tooltip(trans('pelican-minecraft-modrinth::strings.actions.update'))
                    ->hidden(fn (array $record): bool => $record['not_on_modrinth'] ?? false)
                    ->visible(function (array $record) {
                        if (empty($record['project_id'])) {
                            return false;
                        }

                        $installedMod = $this->getInstalledMod($record['project_id']);

                        if (is_null($installedMod)) {
                            return false;
                        }

                        $versions = $this->getCachedVersions($record['project_id']);

                        if (empty($versions)) {
                            return false;
                        }

                        return $installedMod['version_id'] !== $versions[0]['id'];
                    })
                    ->requiresConfirmation()
                    ->modalHeading(trans('pelican-minecraft-modrinth::strings.modals.update_heading'))
                    ->modalDescription(function (array $record) {
                        $installedMod = $this->getInstalledMod($record['project_id']);
                        $versions = $this->getCachedVersions($record['project_id']);

                        return trans('pelican-minecraft-modrinth::strings.modals.update_description', [
                            'old_version' => $installedMod['version_number'] ?? 'unknown',
                            'new_version' => $versions[0]['version_number'] ?? 'unknown',
                        ]);
                    })
                    ->action(function (array $record, DaemonFileRepository $fileRepository) {
                        try {
                            /** @var Server $server */
                            $server = Filament::getTenant();

                            $installedMod = $this->getInstalledMod($record['project_id']);

                            if (!$installedMod) {
                                throw new Exception('Mod not found in metadata');
                            }

                            $versions = MinecraftModrinth::getModrinthVersions($record['project_id'], $server);

                            if (empty($versions)) {
                                throw new Exception('No compatible versions found');
                            }

                            $latestVersion = $versions[0];

                            if (!isset($latestVersion['id'], $latestVersion['version_number'], $latestVersion['files'])) {
                                throw new Exception('Invalid version data structure');
                            }

                            $primaryFile = $this->getPrimaryFile($latestVersion['files']);

                            if (!$primaryFile) {
                                throw new Exception('No downloadable file found');
                            }

                            $this->performInstallOrUpdate($server, $fileRepository, $record, $latestVersion, $primaryFile, $installedMod);

                            $this->installedModsMetadata = null;
                            $this->versionsCache = [];

                            Notification::make()
                                ->title(trans('pelican-minecraft-modrinth::strings.notifications.update_success'))
                                ->body(trans('pelican-minecraft-modrinth::strings.notifications.update_success_body', [
                                    'version' => $latestVersion['version_number'],
                                ]))
                                ->success()
                                ->send();
                        } catch (Exception $exception) {
                            report($exception);

                            $this->installedModsMetadata = null;
                            $this->versionsCache = [];

                            Notification::make()
                                ->title(trans('pelican-minecraft-modrinth::strings.notifications.update_failed'))
                                ->body(trans('pelican-minecraft-modrinth::strings.notifications.update_failed_body'))
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('installed')
                    ->iconButton()
                    ->icon('tabler-check')
                    ->color('success')
                    ->tooltip(trans('pelican-minecraft-modrinth::strings.actions.installed'))
                    ->disabled()
                    ->hidden(fn (array $record): bool => $record['not_on_modrinth'] ?? false)
                    ->visible(function (array $record) {
                        if (empty($record['project_id'])) {
                            return false;
                        }

                        $installedMod = $this->getInstalledMod($record['project_id']);

                        if (is_null($installedMod)) {
                            return false;
                        }

                        $versions = $this->getCachedVersions($record['project_id']);

                        if (empty($versions)) {
                            return true;
                        }

                        return $installedMod['version_id'] === $versions[0]['id'];
                    }),
                Action::make('uninstall')
                    ->iconButton()
                    ->icon('tabler-trash')
                    ->color('danger')
                    ->tooltip(trans('pelican-minecraft-modrinth::strings.actions.uninstall'))
                    ->hidden(fn (array $record): bool => $record['not_on_modrinth'] ?? false)
                    ->visible(function (array $record) {
                        if (empty($record['project_id'])) {
                            return false;
                        }

                        return !is_null($this->getInstalledMod($record['project_id']));
                    })
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record) => trans('pelican-minecraft-modrinth::strings.modals.uninstall_heading'))
                    ->modalDescription(fn (array $record) => trans('pelican-minecraft-modrinth::strings.modals.uninstall_description', ['name' => $record['title']]))
                    ->action(function (array $record, DaemonFileRepository $fileRepository) {
                        try {
                            /** @var Server $server */
                            $server = Filament::getTenant();

                            $installedMod = $this->getInstalledMod($record['project_id']);

                            if (!$installedMod) {
                                throw new Exception('Mod not found in metadata');
                            }

                            $safeFilename = $this->validateFilename($installedMod['filename']);

                            $type = ModrinthProjectType::fromServer($server);
                            if (!$type) {
                                throw new Exception('Server does not support Modrinth mods or plugins');
                            }

                            $folder = $type->getFolder();

                            Http::daemon($server->node)
                                ->post("/api/servers/{$server->uuid}/files/delete", [
                                    'root' => '/',
                                    'files' => [$folder . '/' . $safeFilename],
                                ])
                                ->throw();

                            $metadataRemoved = MinecraftModrinth::removeModMetadata($server, $fileRepository, $record['project_id']);

                            if (!$metadataRemoved) {
                                Log::warning('Failed to remove mod metadata after successful file deletion', [
                                    'project_id' => $record['project_id'],
                                    'server_id' => $server->id,
                                ]);

                                if (is_array($this->installedModsMetadata)) {
                                    $this->installedModsMetadata = array_values(
                                        array_filter($this->installedModsMetadata, fn ($mod) => $mod['project_id'] !== $record['project_id'])
                                    );
                                }

                                unset($this->versionsCache[$record['project_id']]);
                            } else {
                                $this->installedModsMetadata = null;
                                $this->versionsCache = [];
                            }

                            if ($this->activeTab === 'installed') {
                                $this->js('$wire.$refresh()');
                            }

                            Notification::make()
                                ->title(trans('pelican-minecraft-modrinth::strings.notifications.uninstall_success'))
                                ->body(trans('pelican-minecraft-modrinth::strings.notifications.uninstall_success_body', [
                                    'name' => $record['title'],
                                ]))
                                ->success()
                                ->send();
                        } catch (Exception $exception) {
                            report($exception);

                            $this->installedModsMetadata = null;
                            $this->versionsCache = [];

                            if ($this->activeTab === 'installed') {
                                $this->js('$wire.$refresh()');
                            }

                            Notification::make()
                                ->title(trans('pelican-minecraft-modrinth::strings.notifications.uninstall_failed'))
                                ->body(trans('pelican-minecraft-modrinth::strings.notifications.uninstall_failed_body'))
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    protected function getHeaderActions(): array
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        $type = ModrinthProjectType::fromServer($server);
        if (!$type) {
            return [];
        }

        $folder = $type->getFolder();

        return [
            Action::make('open_folder')
                ->tooltip(fn () => trans('pelican-minecraft-modrinth::strings.page.open_folder', ['folder' => $folder]))
                ->icon('tabler-folder-open')
                ->url(fn () => ListFiles::getUrl(['path' => $folder]), true),
            Action::make('update_all')
                ->iconButton()
                ->icon('tabler-download')
                ->color('warning')
                ->tooltip(fn () => trans(match ($type) {
                    ModrinthProjectType::Plugin => 'pelican-minecraft-modrinth::strings.actions.update_all_plugins',
                    default => 'pelican-minecraft-modrinth::strings.actions.update_all_mods',
                }))
                ->requiresConfirmation()
                ->action(function (DaemonFileRepository $fileRepository) use ($server) {
                    $result = $this->performBulkUpdate($server, $fileRepository);

                    if ($result['updated'] === 0 && $result['failed'] === 0) {
                        Notification::make()
                            ->title(trans('pelican-minecraft-modrinth::strings.notifications.bulk_update_none'))
                            ->info()
                            ->send();

                        return;
                    }

                    if ($result['failed'] > 0) {
                        Notification::make()
                            ->title(trans('pelican-minecraft-modrinth::strings.notifications.bulk_update_partial', [
                                'updated' => $result['updated'],
                                'failed' => $result['failed'],
                            ]))
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(trans('pelican-minecraft-modrinth::strings.notifications.bulk_update_success', [
                            'count' => $result['updated'],
                        ]))
                        ->success()
                        ->send();
                })
                ->visible(fn () => ModrinthProjectType::fromServer($server) !== null && $this->activeTab === 'installed'),
            Action::make('scan_mods')
                ->label(trans('pelican-minecraft-modrinth::strings.actions.scan'))
                ->tooltip(fn () => trans(match ($type) {
                    ModrinthProjectType::Plugin => 'pelican-minecraft-modrinth::strings.actions.rescan_plugins_for_updates',
                    default => 'pelican-minecraft-modrinth::strings.actions.rescan_mods_for_updates',
                }))
                ->icon('heroicon-o-magnifying-glass')
                ->action(function () use ($server) {
                    Cache::forget("modrinth_hash_scan:{$server->id}");
                    $this->redirect(static::getUrl());
                })
                ->visible(fn () => ModrinthProjectType::fromServer($server) !== null),
        ];
    }

    public function content(Schema $schema): Schema
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        $type = ModrinthProjectType::fromServer($server);

        return $schema
            ->components([
                Grid::make(3)
                    ->schema([
                        TextEntry::make('Minecraft Version')
                            ->state(fn () => MinecraftModrinth::getMinecraftVersion($server) ?? trans('pelican-minecraft-modrinth::strings.page.unknown'))
                            ->badge(),
                        TextEntry::make('Loader')
                            ->state(fn () => MinecraftLoader::fromServer($server)?->getLabel() ?? trans('pelican-minecraft-modrinth::strings.page.unknown'))
                            ->badge(),
                        TextEntry::make('installed')
                            ->label(fn () => trans('pelican-minecraft-modrinth::strings.page.installed', ['type' => $type?->getLabel() ?? 'Modrinth']))
                            ->state(function (DaemonFileRepository $fileRepository) use ($server, $type) {
                                try {
                                    if (!$type) {
                                        return trans('pelican-minecraft-modrinth::strings.page.unknown');
                                    }

                                    $files = $fileRepository->setServer($server)->getDirectory($type->getFolder());

                                    if (isset($files['error'])) {
                                        throw new Exception($files['error']);
                                    }

                                    return collect($files)
                                        ->filter(fn ($file) => $file['mime'] === 'application/jar' || str($file['name'])->lower()->endsWith('.jar'))
                                        ->count();
                                } catch (Exception $exception) {
                                    report($exception);

                                    return trans('pelican-minecraft-modrinth::strings.page.unknown');
                                }
                            })
                            ->badge(),
                    ]),
                $this->getTabsContentComponent(),
                EmbeddedTable::make(),
            ]);
    }
}
