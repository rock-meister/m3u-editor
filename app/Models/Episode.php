<?php

namespace App\Models;

use Exception;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process as SymfonyProcess;
use Illuminate\Support\Str;

class Episode extends Model
{
    use HasFactory;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'new' => 'boolean',
        'enabled' => 'boolean',
        'source_episode_id' => 'integer',
        'user_id' => 'integer',
        'playlist_id' => 'integer',
        'series_id' => 'integer',
        'season_id' => 'integer',
        'episode_num' => 'integer',
        'season' => 'integer',
        'info' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    /**
     * Get the effective playlist (currently only the main playlist is used)
     * This method returns the playlist that should be used for configuration
     */
    public function getEffectivePlaylist()
    {
        return $this->playlist;
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function getFloatingPlayerAttributes(): array
    {
        // Always proxy the internal proxy so we can attempt to transcode the stream for better compatibility
        $url = route('m3u-proxy.episode.player', ['id' => $this->id]);

        // Determine the channel format based on URL or container extension
        $originalUrl = $this->url;
        if (Str::endsWith($originalUrl, '.m3u8') || Str::endsWith($originalUrl, '.ts')) {
            $episodeFormat = 'ts';
        } else {
            $episodeFormat = $this->container_extension ?? 'ts';
        }

        return [
            'id' => 'episode-' . $this->id,
            'title' => $this->title,
            'url' => $url,
            'format' => $episodeFormat,
            'type' => 'episode',
        ];
    }

    /**
     * Get the stream attributes.
     *
     * @var array
     */
    public function getStreamStatsAttribute(): array
    {
        try {
            $url = $this->url;
            $process = SymfonyProcess::fromShellCommandline(
                "ffprobe -v quiet -print_format json -show_streams {$url}"
            );
            $process->setTimeout(10);
            $output = '';
            $errors = '';
            $hasErrors = false;
            $process->run(
                function ($type, $buffer) use (&$output, &$hasErrors, &$errors) {
                    if ($type === SymfonyProcess::OUT) {
                        $output .= $buffer;
                    }
                    if ($type === SymfonyProcess::ERR) {
                        $hasErrors = true;
                        $errors .= $buffer;
                    }
                }
            );
            if ($hasErrors) {
                Log::error("Error running ffprobe for episode \"{$this->title}\": {$errors}");
                return [];
            }
            $json = json_decode($output, true);
            if (isset($json['streams']) && is_array($json['streams'])) {
                $streamStats = [];
                foreach ($json['streams'] as $stream) {
                    if (isset($stream['codec_name'])) {
                        $streamStats[]['stream'] = [
                            'codec_type' => $stream['codec_type'],
                            'codec_name' => $stream['codec_name'],
                            'codec_long_name' => $stream['codec_long_name'] ?? null,
                            'profile' => $stream['profile'] ?? null,
                            'width' => $stream['width'] ?? null,
                            'height' => $stream['height'] ?? null,
                            'bit_rate' => $stream['bit_rate'] ?? null,
                            'avg_frame_rate' => $stream['avg_frame_rate'] ?? null,
                            'display_aspect_ratio' => $stream['display_aspect_ratio'] ?? null,
                            'sample_rate' => $stream['sample_rate'] ?? null,
                            'channels' => $stream['channels'] ?? null,
                            'channel_layout' => $stream['channel_layout'] ?? null,
                        ];
                    }
                }
                return $streamStats;
            }
        } catch (Exception $e) {
            Log::error("Error running ffprobe for episode \"{$this->title}\": {$e->getMessage()}");
        }
        return [];
    }

    /**
     * Get the added attribute with safe parsing
     */
    public function getAddedAttribute($value)
    {
        if (!$value) {
            return null;
        }

        try {
            // If it's a timestamp string, parse it
            if (is_numeric($value)) {
                return Carbon::createFromTimestamp($value);
            }

            // Try to parse as a regular date/time string
            return Carbon::parse($value);
        } catch (Exception $e) {
            // If parsing fails, return null
            return null;
        }
    }
}
