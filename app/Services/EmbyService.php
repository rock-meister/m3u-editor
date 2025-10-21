<?php

namespace App\Services;

use App\Settings\GeneralSettings;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Media Server Service for Emby/Jellyfin Integration
 *
 * This service provides integration with Emby and Jellyfin media servers.
 * Both platforms share the same API structure and endpoints, making them
 * fully compatible with this implementation.
 *
 * Supported Platforms:
 * - Emby Media Server
 * - Jellyfin Media Server
 */
class EmbyService
{
    private ?string $serverUrl;
    private ?string $apiKey;

    public function __construct()
    {
        $settings = app(GeneralSettings::class);
        $this->serverUrl = $settings->emby_server_url ? rtrim($settings->emby_server_url, '/') : null;
        $this->apiKey = $settings->emby_api_key;
    }

    /**
     * Check if media server (Emby/Jellyfin) is configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->serverUrl) && !empty($this->apiKey);
    }

    /**
     * Test connection to media server (Emby/Jellyfin)
     */
    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            throw new Exception('Media server URL and API key must be configured');
        }

        try {
            $response = Http::withHeaders([
                'X-Emby-Token' => $this->apiKey,
            ])->timeout(10)->get($this->serverUrl . '/System/Info');

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                ];
            }

            return [
                'success' => false,
                'error' => 'Failed to connect. Status: ' . $response->status(),
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get all libraries from media server (Emby/Jellyfin)
     */
    public function getLibraries(): array
    {
        if (!$this->isConfigured()) {
            throw new Exception('Media server URL and API key must be configured');
        }

        try {
            $response = Http::withHeaders([
                'X-Emby-Token' => $this->apiKey,
            ])->timeout(30)->get($this->serverUrl . '/Library/VirtualFolders');

            if ($response->successful()) {
                return $response->json();
            }

            throw new Exception('Failed to fetch libraries. Status: ' . $response->status());
        } catch (Exception $e) {
            Log::error('Media server getLibraries error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get items from a specific library
     */
    public function getLibraryItems(string $libraryId, string $itemType = 'Movie'): array
    {
        if (!$this->isConfigured()) {
            throw new Exception('Media server URL and API key must be configured');
        }

        try {
            $url = $this->serverUrl . '/Items';
            $params = [
                'ParentId' => $libraryId,
                'IncludeItemTypes' => $itemType,
                'Recursive' => 'true',
                'Fields' => 'Path,Overview,Genres,Studios,Tags,ProductionYear,PremiereDate,CommunityRating,OfficialRating,MediaStreams,MediaSources,People,RunTimeTicks',
                'SortBy' => 'SortName,Name',
                'SortOrder' => 'Ascending',
            ];
            
            Log::info('Media server API Request', [
                'url' => $url,
                'params' => $params,
            ]);

            $response = Http::withHeaders([
                'X-Emby-Token' => $this->apiKey,
            ])->timeout(60)->get($url, $params);

            if ($response->successful()) {
                $data = $response->json();
                Log::info('Media server API Response', [
                    'total_items' => count($data['Items'] ?? []),
                    'sample' => isset($data['Items'][0]) ? $data['Items'][0]['Name'] : 'none',
                ]);
                return $data['Items'] ?? [];
            }

            Log::error('Media server API failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new Exception('Failed to fetch library items. Status: ' . $response->status() . ' - ' . $response->body());
        } catch (Exception $e) {
            Log::error('Media server getLibraryItems error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get detailed information about a specific item
     */
    public function getItem(string $itemId): array
    {
        if (!$this->isConfigured()) {
            throw new Exception('Media server URL and API key must be configured');
        }

        try {
            $response = Http::withHeaders([
                'X-Emby-Token' => $this->apiKey,
            ])->timeout(30)->get($this->serverUrl . "/Items/{$itemId}");

            if ($response->successful()) {
                return $response->json();
            }

            throw new Exception('Failed to fetch item details. Status: ' . $response->status());
        } catch (Exception $e) {
            Log::error('Media server getItem error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get seasons for a TV series
     */
    public function getSeasons(string $seriesId): array
    {
        if (!$this->isConfigured()) {
            throw new Exception('Media server URL and API key must be configured');
        }

        try {
            $response = Http::withHeaders([
                'X-Emby-Token' => $this->apiKey,
            ])->timeout(30)->get($this->serverUrl . '/Shows/' . $seriesId . '/Seasons', [
                'Fields' => 'Overview',
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['Items'] ?? [];
            }

            throw new Exception('Failed to fetch seasons. Status: ' . $response->status());
        } catch (Exception $e) {
            Log::error('Media server getSeasons error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get episodes for a season
     */
    public function getEpisodes(string $seriesId, string $seasonId): array
    {
        if (!$this->isConfigured()) {
            throw new Exception('Media server URL and API key must be configured');
        }

        try {
            $response = Http::withHeaders([
                'X-Emby-Token' => $this->apiKey,
            ])->timeout(30)->get($this->serverUrl . '/Shows/' . $seriesId . '/Episodes', [
                'SeasonId' => $seasonId,
                'Fields' => 'Path,Overview,MediaStreams,MediaSources,People,PremiereDate,RunTimeTicks,CommunityRating,OfficialRating',
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['Items'] ?? [];
            }

            throw new Exception('Failed to fetch episodes. Status: ' . $response->status());
        } catch (Exception $e) {
            Log::error('Media server getEpisodes error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get image URL for an item
     * Note: API key is required in URL for images as they're accessed directly by browsers
     * Consider implementing a proxy endpoint if you need to hide the API key
     * Compatible with both Emby and Jellyfin
     */
    public function getImageUrl(string $itemId, string $imageType = 'Primary'): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        return $this->serverUrl . "/Items/{$itemId}/Images/{$imageType}?api_key=" . $this->apiKey;
    }

    /**
     * Get streaming URL for an item
     * Note: API key is required in URL for streaming as media players don't support custom headers
     * This is a limitation of the media server API when used with external players
     * Compatible with both Emby and Jellyfin
     */
    public function getStreamUrl(string $itemId): string
    {
        if (!$this->isConfigured()) {
            throw new Exception('Media server URL and API key must be configured');
        }

        // Use lowercase 'static' parameter as per API specification
        return $this->serverUrl . "/Videos/{$itemId}/stream?api_key=" . $this->apiKey . "&Static=true";
    }

    /**
     * Get streaming URL with device ID for better security tracking
     * Media servers can track which device/app is accessing content
     * Compatible with both Emby and Jellyfin
     */
    public function getStreamUrlWithDeviceId(string $itemId, string $deviceId = 'M3U-Editor'): string
    {
        if (!$this->isConfigured()) {
            throw new Exception('Media server URL and API key must be configured');
        }

        return $this->serverUrl . "/Videos/{$itemId}/stream?api_key=" . $this->apiKey . "&Static=true&DeviceId=" . urlencode($deviceId);
    }

    /**
     * Get direct file path from item
     */
    public function getFilePath(array $item): ?string
    {
        return $item['Path'] ?? $item['MediaSources'][0]['Path'] ?? null;
    }

    /**
     * Extract cast names from People array
     */
    public function extractCast(array $people): ?string
    {
        $actors = array_filter($people, function ($person) {
            return isset($person['Type']) && $person['Type'] === 'Actor';
        });

        if (empty($actors)) {
            return null;
        }

        $names = array_map(function ($actor) {
            return $actor['Name'] ?? '';
        }, $actors);

        return implode(', ', array_filter($names));
    }

    /**
     * Extract director name from People array
     */
    public function extractDirector(array $people): ?string
    {
        foreach ($people as $person) {
            if (isset($person['Type']) && $person['Type'] === 'Director') {
                return $person['Name'] ?? null;
            }
        }

        return null;
    }

    /**
     * Format duration from seconds to HH:MM:SS
     */
    public function formatDuration(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }

    /**
     * Extract genres from an item for grouping purposes
     */
    public function extractGenresForGrouping(array $item): array
    {
        $genres = $item['Genres'] ?? [];
        
        // Filter out empty genres and clean them up
        $cleanGenres = array_filter(array_map('trim', $genres));
        
        return array_values($cleanGenres);
    }

    /**
     * Check if groups/categories should be created from genres
     */
    public function shouldCreateGroupsFromGenres(?bool $override = null): bool
    {
        if ($override !== null) {
            return $override;
        }
        
        $settings = app(\App\Settings\GeneralSettings::class);
        return $settings->emby_import_groups_categories ?? false;
    }

    /**
     * Check if content should be created in multiple genres
     */
    public function shouldCreateMultipleGenreEntries(): bool
    {
        $settings = app(\App\Settings\GeneralSettings::class);
        $genreHandling = $settings->emby_genre_handling ?? 'primary';
        $result = $genreHandling === 'all';
        
        Log::info('🔍 DEBUG: shouldCreateMultipleGenreEntries called', [
            'emby_genre_handling_setting' => $genreHandling,
            'returns' => $result,
            'comparison' => $genreHandling . ' === "all"',
        ]);
        
        return $result;
    }

    /**
     * Get target genres based on user configuration
     */
    public function getTargetGenres(array $genres): array
    {
        if (empty($genres)) {
            Log::info('🔍 DEBUG: getTargetGenres - empty genres array');
            return [];
        }

        $shouldCreateMultiple = $this->shouldCreateMultipleGenreEntries();
        $result = $shouldCreateMultiple ? $genres : [reset($genres)];
        
        Log::info('🔍 DEBUG: getTargetGenres result', [
            'input_genres' => $genres,
            'input_count' => count($genres),
            'shouldCreateMultiple' => $shouldCreateMultiple,
            'output_genres' => $result,
            'output_count' => count($result),
        ]);

        return $result;
    }

    /**
     * Create groups from genres for VOD content
     */
    public function createGroupsFromGenres(array $genres, \App\Models\Playlist $playlist, string $batchNo): \Illuminate\Support\Collection
    {
        $groups = collect();
        
        foreach ($genres as $genre) {
            if (empty($genre)) {
                continue;
            }

            $group = \App\Models\Group::firstOrCreate([
                'name_internal' => $genre,
                'playlist_id' => $playlist->id,
                'user_id' => $playlist->user_id,
                'custom' => false,
            ], [
                'name' => $genre,
                'import_batch_no' => $batchNo,
            ]);

            $groups->push($group);
        }

        return $groups;
    }

    /**
     * Create categories from genres for series content
     */
    public function createCategoriesFromGenres(array $genres, \App\Models\Playlist $playlist): \Illuminate\Support\Collection
    {
        $categories = collect();
        
        foreach ($genres as $genre) {
            if (empty($genre)) {
                continue;
            }

            $category = \App\Models\Category::firstOrCreate([
                'name_internal' => $genre,
                'playlist_id' => $playlist->id,
            ], [
                'name' => $genre,
                'user_id' => $playlist->user_id,
                'enabled' => true,
            ]);

            $categories->push($category);
        }

        return $categories;
    }

    /**
     * Process item genres and create appropriate groups/categories
     */
    public function processItemGenres(array $item, \App\Models\Playlist $playlist, string $batchNo, string $type = 'group', ?bool $enableGenreGrouping = null): \Illuminate\Support\Collection
    {
        if (!$this->shouldCreateGroupsFromGenres($enableGenreGrouping)) {
            return collect();
        }

        $genres = $this->extractGenresForGrouping($item);
        $targetGenres = $this->getTargetGenres($genres);

        if ($type === 'category') {
            return $this->createCategoriesFromGenres($targetGenres, $playlist);
        }

        return $this->createGroupsFromGenres($targetGenres, $playlist, $batchNo);
    }
}