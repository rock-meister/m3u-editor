<?php

namespace App\Filament\Resources\CustomPlaylists;

use Illuminate\Support\Facades\Auth;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\CustomPlaylists\RelationManagers\ChannelsRelationManager;
use App\Filament\Resources\CustomPlaylists\RelationManagers\VodRelationManager;
use App\Filament\Resources\CustomPlaylists\RelationManagers\GroupsRelationManager;
use App\Filament\Resources\CustomPlaylists\RelationManagers\SeriesRelationManager;
use App\Filament\Resources\CustomPlaylists\RelationManagers\CategoriesRelationManager;
use App\Filament\Resources\CustomPlaylists\Pages\ListCustomPlaylists;
use App\Filament\Resources\CustomPlaylists\Pages\EditCustomPlaylist;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Validation\Rule;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use App\Models\PlaylistAuth;
use App\Filament\Resources\CustomPlaylistResource\Pages;
use App\Filament\Resources\CustomPlaylistResource\RelationManagers;
use App\Forms\Components\PlaylistEpgUrl;
use App\Forms\Components\PlaylistM3uUrl;
use App\Forms\Components\MediaFlowProxyUrl;
use App\Models\CustomPlaylist;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Facades\PlaylistFacade;
use App\Filament\Resources\CustomPlaylists\Pages\ViewCustomPlaylist;
use App\Forms\Components\XtreamApiInfo;
use App\Models\SharedStream;
use App\Services\EpgCacheService;
use App\Services\M3uProxyService;
use App\Services\ProxyService;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\FormsComponent;
use Filament\Schemas\Components\Fieldset;
use Illuminate\Support\Facades\Redis;

class CustomPlaylistResource extends Resource
{
    protected static ?string $model = CustomPlaylist::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->where('user_id', Auth::id());
    }

    protected static string | \UnitEnum | null $navigationGroup = 'Playlist';

    public static function getNavigationSort(): ?int
    {
        return 2;
    }

    public static function form(Schema $schema): Schema
    {
        $isCreating = $schema->getOperation() === 'create';
        return $schema
            ->components(self::getForm($isCreating));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                $query->withCount('live_channels')
                    ->withCount('vod_channels')
                    ->withCount('series')
                    ->withCount('enabled_series')
                    ->withCount('enabled_live_channels')
                    ->withCount('enabled_vod_channels');
            })
            ->columns([
                TextColumn::make('id')
                    ->searchable()
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('available_streams')
                    ->label('Streams')
                    ->toggleable()
                    ->formatStateUsing(fn(int $state): string => $state === 0 ? '∞' : (string)$state)
                    ->tooltip('Total streams available for this playlist (∞ indicates no limit)')
                    ->description(fn(CustomPlaylist $record): string => "Active: " . M3uProxyService::getPlaylistActiveStreamsCount($record))
                    ->sortable(),
                TextColumn::make('live_channels_count')
                    ->label('Live')
                    ->description(fn(CustomPlaylist $record): string => "Enabled: {$record->enabled_live_channels_count}")
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('vod_channels_count')
                    ->label('VOD')
                    ->description(fn(CustomPlaylist $record): string => "Enabled: {$record->enabled_vod_channels_count}")
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('series_count')
                    ->label('Series')
                    ->description(fn(CustomPlaylist $record): string => "Enabled: {$record->enabled_series_count}")
                    ->toggleable()
                    ->sortable(),
                ToggleColumn::make('enable_proxy')
                    ->label('Proxy')
                    ->toggleable()
                    ->tooltip('Toggle proxy status')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('Download M3U')
                        ->label('Download M3U')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->url(fn($record) => PlaylistFacade::getUrls($record)['m3u'])
                        ->openUrlInNewTab(),
                    EpgCacheService::getEpgTableAction(),
                    Action::make('HDHomeRun URL')
                        ->label('HDHomeRun URL')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn($record) => PlaylistFacade::getUrls($record)['hdhr'])
                        ->openUrlInNewTab(),
                    Action::make('Public URL')
                        ->label('Public URL')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn($record) => '/playlist/v/' . $record->uuid)
                        ->openUrlInNewTab(),
                    DeleteAction::make(),
                ])->button()->hiddenLabel()->size('sm'),
                EditAction::make()
                    ->button()->hiddenLabel()->size('sm'),
                ViewAction::make()
                    ->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ChannelsRelationManager::class,
            VodRelationManager::class,
            SeriesRelationManager::class,
            GroupsRelationManager::class,
            CategoriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomPlaylists::route('/'),
            // 'create' => Pages\CreateCustomPlaylist::route('/create'),
            'view' => ViewCustomPlaylist::route('/{record}'),
            'edit' => EditCustomPlaylist::route('/{record}/edit'),
        ];
    }

    public static function getForm($creating = false): array
    {
        $schema = [
            Grid::make()
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->helperText('Enter the name of the playlist. Internal use only.'),
                    TextInput::make('user_agent')
                        ->helperText('User agent string to use for making requests.')
                        ->default('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36')
                        ->required(),
                ]),
            Grid::make()
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    Toggle::make('short_urls_enabled')
                        ->label('Use Short URLs')
                        ->helperText('When enabled, short URLs will be used for the playlist links. Save changes to generate the short URLs (or remove them).')
                        ->columnSpan(2)
                        ->inline(false)
                        ->default(false),
                    Toggle::make('edit_uuid')
                        ->label('View/Update Unique Identifier')
                        ->columnSpanFull()
                        ->inline(false)
                        ->live()
                        ->dehydrated(false)
                        ->default(false),
                    TextInput::make('uuid')
                        ->label('Unique Identifier')
                        ->columnSpanFull()
                        ->rules(function ($record) {
                            return [
                                'required',
                                'min:3',
                                'max:36',
                                Rule::unique('playlists', 'uuid')->ignore($record?->id),
                            ];
                        })
                        ->helperText('Value must be between 3 and 36 characters.')
                        ->hintIcon(
                            'heroicon-m-exclamation-triangle',
                            tooltip: 'Be careful changing this value as this will change the URLs for the Playlist, its EPG, and HDHR.'
                        )
                        ->hidden(fn($get): bool => !$get('edit_uuid'))
                        ->required(),
                ])->hiddenOn('create'),
        ];
        $outputScheme = [
            Section::make('Playlist Output')
                ->description('Determines how the playlist is output')
                ->columnSpanFull()
                ->collapsible()
                ->collapsed($creating)
                ->columns(2)
                ->schema([
                    Toggle::make('auto_channel_increment')
                        ->label('Auto channel number increment')
                        ->columnSpan(1)
                        ->inline(false)
                        ->live()
                        ->default(false)
                        ->helperText('If no channel number is set, output an automatically incrementing number.'),
                    TextInput::make('channel_start')
                        ->helperText('The starting channel number.')
                        ->columnSpan(1)
                        ->rules(['min:1'])
                        ->type('number')
                        ->hidden(fn(Get $get): bool => !$get('auto_channel_increment'))
                        ->required(),
                ]),
            Section::make('EPG Output')
                ->description('EPG output options')
                ->columnSpanFull()
                ->collapsible()
                ->collapsed($creating)
                ->columns(2)
                ->schema([
                    Toggle::make('dummy_epg')
                        ->label('Enably dummy EPG')
                        ->columnSpan(1)
                        ->live()
                        ->inline(false)
                        ->default(false)
                        ->helperText('When enabled, dummy EPG data will be generated for the next 5 days. Thus, it is possible to assign channels for which no EPG data is available. As program information, the channel name and the set program length are used.'),
                    Select::make('id_channel_by')
                        ->label('Preferred TVG ID output')
                        ->helperText('How you would like to ID your channels in the EPG.')
                        ->options([
                            'stream_id' => 'TVG ID/Stream ID (default)',
                            'channel_id' => 'Channel Number (recommended for HDHR)',
                            'name' => 'Channel Name',
                            'title' => 'Channel Title',
                        ])
                        ->required()
                        ->default('stream_id') // Default to stream_id
                        ->columnSpan(1),
                    TextInput::make('dummy_epg_length')
                        ->label('Dummy program length (in minutes)')
                        ->columnSpan(1)
                        ->rules(['min:1'])
                        ->type('number')
                        ->default(120)
                        ->hidden(fn(Get $get): bool => !$get('dummy_epg'))
                        ->required(),
                ]),
            Section::make('Streaming Output')
                ->description('Output processing options')
                ->columnSpanFull()
                ->collapsible()
                ->collapsed($creating)
                ->columns(2)
                ->schema([
                    Toggle::make('enable_proxy')
                        ->label('Enable Stream Proxy')
                        ->hint(fn(Get $get): string => $get('enable_proxy') ? 'Proxied' : 'Not proxied')
                        ->hintIcon(fn(Get $get): string => !$get('enable_proxy') ? 'heroicon-m-lock-open' : 'heroicon-m-lock-closed')
                        ->live()
                        ->helperText('When enabled, all streams will be proxied through the application. This allows for better compatibility with various clients and enables features such as stream limiting and output format selection.')
                        ->inline(false)
                        ->default(false),
                    Toggle::make('enable_logo_proxy')
                        ->label('Enable Logo Proxy')
                        ->hint(fn(Get $get): string => $get('enable_logo_proxy') ? 'Proxied' : 'Not proxied')
                        ->hintIcon(fn(Get $get): string => !$get('enable_logo_proxy') ? 'heroicon-m-lock-open' : 'heroicon-m-lock-closed')
                        ->live()
                        ->helperText('When enabled, channel logos will be proxied through the application. Logos will be cached for up to 30 days to reduce bandwidth and speed up loading times.')
                        ->inline(false)
                        ->default(false),
                    TextInput::make('streams')
                        ->label('HDHR/Xtream API Streams')
                        ->helperText('Number of streams available for HDHR and Xtream API service (if using).')
                        ->columnSpan(1)
                        ->hintIcon(
                            'heroicon-m-question-mark-circle',
                            tooltip: 'This value is also used when generating the Xtream API user info response.'
                        )
                        ->rules(['min:1'])
                        ->type('number')
                        ->default(1) // Default to 1 stream
                        ->required(),
                    TextInput::make('available_streams')
                        ->label('Available Streams')
                        ->hint('Set to 0 for unlimited streams.')
                        ->helperText('Number of streams available for this playlist (only applies to custom channels assigned to this Custom Playlist).')
                        ->columnSpan(1)
                        ->rules(['min:0'])
                        ->type('number')
                        ->default(0) // Default to 0 streams (for unlimted)
                        ->required()
                        ->hidden(fn(Get $get): bool => !$get('enable_proxy')),
                    TextInput::make('server_timezone')
                        ->label('Provider Timezone')
                        ->columnSpanFull()
                        ->helperText('The portal/provider timezone (DST-aware). Needed to correctly use timeshift functionality when playlist proxy is enabled.')
                        ->placeholder('Etc/UTC')
                        ->hidden(fn(Get $get): bool => !$get('enable_proxy')),

                    Fieldset::make('Transcoding Settings (optional)')
                        ->columnSpanFull()
                        ->schema([
                            Select::make('stream_profile_id')
                                ->label('Default Streaming Profile')
                                ->relationship('streamProfile', 'name')
                                ->searchable()
                                ->preload()
                                ->nullable()
                                ->helperText('Select a transcoding profile to apply to streams from this playlist. Leave empty for direct streaming.')
                                ->placeholder('Leave empty for direct streaming'),
                            Select::make('vod_stream_profile_id')
                                ->label('VOD and Series Streaming Profile')
                                ->relationship('vodStreamProfile', 'name')
                                ->searchable()
                                ->preload()
                                ->nullable()
                                ->helperText('Select a transcoding profile to apply to streams from this playlist. Leave empty to use default profile or direct streaming.')
                                ->placeholder('Leave empty for default profile or direct streaming'),
                        ])->hidden(fn(Get $get): bool => ! $get('enable_proxy')),

                    Fieldset::make('HTTP Headers (optional)')
                        ->columnSpanFull()
                        ->schema([
                            Repeater::make('custom_headers')
                                ->hiddenLabel()
                                ->helperText('Add any custom headers to include when streaming a channel/episode.')
                                ->columnSpanFull()
                                ->columns(2)
                                ->default([])
                                ->schema([
                                    TextInput::make('header')
                                        ->label('Header')
                                        ->required()
                                        ->placeholder('e.g. Authorization'),
                                    TextInput::make('value')
                                        ->label('Value')
                                        ->required()
                                        ->placeholder('e.g. Bearer abc123'),
                                ])
                        ])->hidden(fn(Get $get): bool => !$get('enable_proxy'))
                ])
        ];
        return [
            Grid::make()
                ->hiddenOn(['edit']) // hide this field on the edit form
                ->schema([
                    ...$schema,
                    ...$outputScheme
                ])
                ->columns(2),
            Grid::make()
                ->hiddenOn(['create']) // hide this field on the create form
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    Tabs::make('tabs')
                        ->columnSpanFull()
                        ->contained(false)
                        ->persistTabInQueryString()
                        ->tabs([
                            Tab::make('General')
                                ->columns(2)
                                ->icon('heroicon-m-cog')
                                ->schema([
                                    Section::make('Playlist Settings')
                                        ->compact()
                                        ->collapsible()
                                        ->collapsed(true)
                                        ->icon('heroicon-m-cog')
                                        ->columnSpan(2)
                                        ->schema([
                                            ...$schema,

                                        ])
                                ]),

                            Tab::make('Auth')
                                ->columns(2)
                                ->icon('heroicon-m-key')
                                ->schema([
                                    Section::make('Auth')
                                        ->compact()
                                        ->description('Add and manage authentication.')
                                        ->icon('heroicon-m-key')
                                        ->columnSpan(2)
                                        ->schema([
                                            Select::make('assigned_auth_ids')
                                                ->label('Assigned Auths')
                                                ->multiple()
                                                ->options(function ($record) {
                                                    $options = [];

                                                    // Get currently assigned auths for this playlist
                                                    if ($record) {
                                                        $currentAuths = $record->playlistAuths()->get();
                                                        foreach ($currentAuths as $auth) {
                                                            $options[$auth->id] = $auth->name . ' (currently assigned)';
                                                        }
                                                    }

                                                    // Get unassigned auths
                                                    $unassignedAuths = PlaylistAuth::where('user_id', Auth::id())
                                                        ->whereDoesntHave('assignedPlaylist')
                                                        ->get();

                                                    foreach ($unassignedAuths as $auth) {
                                                        $options[$auth->id] = $auth->name;
                                                    }

                                                    return $options;
                                                })
                                                ->searchable()
                                                ->nullable()
                                                ->placeholder('Select auths or leave empty')
                                                ->default(function ($record) {
                                                    if ($record) {
                                                        return $record->playlistAuths()->pluck('playlist_auths.id')->toArray();
                                                    }
                                                    return [];
                                                })
                                                ->afterStateHydrated(function ($component, $state, $record) {
                                                    if ($record) {
                                                        $currentAuthIds = $record->playlistAuths()->pluck('playlist_auths.id')->toArray();
                                                        $component->state($currentAuthIds);
                                                    }
                                                })
                                                ->helperText('Only unassigned auths are available. Each auth can only be assigned to one playlist at a time.')
                                                ->afterStateUpdated(function ($state, $record) {
                                                    if (!$record) return;

                                                    $currentAuthIds = $record->playlistAuths()->pluck('playlist_auths.id')->toArray();
                                                    $newAuthIds = $state ? (is_array($state) ? $state : [$state]) : [];

                                                    // Find auths to remove (currently assigned but not in new selection)
                                                    $authsToRemove = array_diff($currentAuthIds, $newAuthIds);
                                                    foreach ($authsToRemove as $authId) {
                                                        $auth = PlaylistAuth::find($authId);
                                                        if ($auth) {
                                                            $auth->clearAssignment();
                                                        }
                                                    }

                                                    // Find auths to add (in new selection but not currently assigned)
                                                    $authsToAdd = array_diff($newAuthIds, $currentAuthIds);
                                                    foreach ($authsToAdd as $authId) {
                                                        $auth = PlaylistAuth::find($authId);
                                                        if ($auth) {
                                                            $auth->assignTo($record);
                                                        }
                                                    }
                                                })
                                                ->dehydrated(false), // Don't save this field directly
                                        ])
                                ]),
                            Tab::make('Output')
                                ->icon('heroicon-m-arrow-up-right')
                                ->columns(2)
                                ->schema($outputScheme),
                        ]),
                ]),
        ];
    }
}
