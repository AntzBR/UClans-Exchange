<?php

declare(strict_types=1);

final class SqliteMigrator
{
    public static function migrate(string $userRoot, string $outputRoot, string $templateRoot, array &$report, array $languagePolicy = []): void
    {
        $report['storage'] ??= [];
        $report['storage_errors'] ??= [];
        $report['storage_warnings'] ??= [];
        $report['data_validation'] ??= [];
        $report['data_validation_warnings'] ??= [];
        $report['data_validation_errors'] ??= [];
        $report['language_safety'] ??= [];
        $report['unmapped'] ??= [];

        $sourceCore = $userRoot . '/storage/sqlite/clans.db';
        $hasCore = is_file($sourceCore);
        $hasAddonSqlite = self::hasAddonSqliteFiles($userRoot);
        if (!$hasCore && !$hasAddonSqlite) return;

        if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $report['storage_errors'][] = 'SQLite data was detected, but the PHP pdo_sqlite driver is not available. Configuration was converted, but SQLite data was not migrated.';
            return;
        }

        // Core and addons choose their storage independently. A missing or stale
        // core clans.db must never suppress active addon SQLite migration.
        if ($hasCore) {
            $storageType = self::storageType($userRoot);
            if ($storageType !== null && $storageType !== 'sqlite') {
                $report['storage_warnings'][] = 'A storage/sqlite/clans.db file exists, but Storage.type is ' . $storageType . '; the core SQLite database was treated as inactive/stale and was not migrated. Addon storage is evaluated independently.';
            } else {
                $report['storage'][] = 'SQLite storage detected: storage/sqlite/clans.db';
                $wal = $sourceCore . '-wal';
                if (is_file($wal)) $report['storage_warnings'][] = 'A SQLite WAL file was detected next to clans.db and will be visible to SQLite during migration. For the safest backup, stop the Minecraft server before creating the ZIP.';
                else $report['storage_warnings'][] = 'For a consistent SQLite backup, create the ZIP while the Minecraft server/plugin is stopped.';

                try {
                    $source = self::open($sourceCore, true);
                    $sourceIntegrity = self::integrity($source);
                    if ($sourceIntegrity !== 'ok') {
                        $report['storage_errors'][] = 'Source storage/sqlite/clans.db failed PRAGMA integrity_check: ' . $sourceIntegrity;
                    } else {
                        $report['storage'][] = 'Source clans.db integrity: OK';
                        $tableNames = self::coreTableNames($userRoot);
                        $targets = [
                            [
                                'label' => 'Core clans.db',
                                'template' => 'storage/sqlite/clans.db',
                                'output' => 'storage/sqlite/clans.db',
                                'tables' => [
                                    ['source' => $tableNames['clan'], 'template' => 'uclans-clans', 'destination' => $tableNames['clan']],
                                    ['source' => $tableNames['player'], 'template' => 'uclans-players', 'destination' => $tableNames['player']],
                                    ['source' => $tableNames['invite'], 'template' => 'uclans-invite', 'destination' => $tableNames['invite']],
                                    ['source' => $tableNames['moderation'], 'template' => 'uclans-moderation', 'destination' => $tableNames['moderation']],
                                ],
                            ],
                            [
                                'label' => 'Logger module',
                                'template' => 'modules/Logger/storage/sqlite/logger.db',
                                'output' => 'modules/Logger/storage/sqlite/logger.db',
                                'tables' => [['source' => $tableNames['logger'], 'template' => 'uclans-logger', 'destination' => $tableNames['logger']]],
                            ],
                            [
                                'label' => 'Mail module',
                                'template' => 'modules/Mail/storage/sqlite/mail.db',
                                'output' => 'modules/Mail/storage/sqlite/mail.db',
                                'tables' => [['source' => $tableNames['mail'], 'template' => 'uclans-mail', 'destination' => $tableNames['mail']]],
                            ],
                            [
                                'label' => 'Rewards module',
                                'template' => 'modules/Rewards/storage/sqlite/rewards.db',
                                'output' => 'modules/Rewards/storage/sqlite/rewards.db',
                                'tables' => [['source' => $tableNames['rewards'], 'template' => 'uclans-rewards', 'destination' => $tableNames['rewards']]],
                            ],
                        ];

                        foreach ($targets as $target) {
                            self::migrateDatabaseFromSharedSource($source, $outputRoot, $templateRoot, $target, $report);
                        }

                        if ($languagePolicy) self::auditPlayerLanguages($outputRoot, $tableNames, $languagePolicy, $report);
                        self::validateCoreRelations($outputRoot, $tableNames, $report);
                    }
                } catch (Throwable $e) {
                    $report['storage_errors'][] = 'Core SQLite migration stopped: ' . $e->getMessage();
                }
            }
        }

        if ($hasAddonSqlite) {
            try {
                self::migrateAddonDatabases($userRoot, $outputRoot, $templateRoot, $report);
            } catch (Throwable $e) {
                $report['storage_errors'][] = 'Addon SQLite migration stopped: ' . $e->getMessage();
            }
        }
    }

    private static function migrateDatabaseFromSharedSource(PDO $source, string $outputRoot, string $templateRoot, array $target, array &$report): void
    {
        $template = $templateRoot . '/' . $target['template'];
        if (!is_file($template)) {
            $report['storage_errors'][] = $target['label'] . ': V9 SQLite template is missing (' . $target['template'] . ').';
            return;
        }

        $destFile = $outputRoot . '/' . $target['output'];
        @mkdir(dirname($destFile), 0700, true);
        if (!copy($template, $destFile)) {
            $report['storage_errors'][] = $target['label'] . ': could not create destination database.';
            return;
        }

        $dest = self::open($destFile, false);
        foreach ($target['tables'] as $map) {
            $sourceTable = (string)$map['source'];
            $templateTable = (string)$map['template'];
            $destinationTable = (string)$map['destination'];
            if (!self::tableExists($source, $sourceTable)) {
                $report['storage_warnings'][] = $target['label'] . ': source table ' . $sourceTable . ' was not found; V9 table was left empty.';
                continue;
            }
            if (!self::tableExists($dest, $templateTable)) {
                $report['storage_errors'][] = $target['label'] . ': destination template does not contain table ' . $templateTable . '.';
                continue;
            }
            if ($destinationTable !== $templateTable) {
                if (self::tableExists($dest, $destinationTable)) $dest->exec('DROP TABLE ' . self::qi($destinationTable));
                $dest->exec('ALTER TABLE ' . self::qi($templateTable) . ' RENAME TO ' . self::qi($destinationTable));
                $report['storage'][] = $target['output'] . ': V9 table ' . $templateTable . ' renamed to configured table ' . $destinationTable;
            }
            self::copyTable($source, $dest, $sourceTable, $destinationTable, $target['output'], $report);
        }

        $integrity = self::integrity($dest);
        if ($integrity === 'ok') $report['storage'][] = $target['output'] . ' integrity: OK';
        else $report['storage_errors'][] = $target['output'] . ' failed PRAGMA integrity_check: ' . $integrity;
    }

    private static function auditPlayerLanguages(string $outputRoot, array $tableNames, array $policy, array &$report): void
    {
        $supported = array_values(array_unique(array_map(static fn($v) => strtoupper(trim((string)$v)), (array)($policy['supported'] ?? []))));
        $customCore = array_values(array_unique(array_map(static fn($v) => strtoupper(trim((string)$v)), (array)($policy['custom_core'] ?? []))));

        $file = $outputRoot . '/storage/sqlite/clans.db';
        if (!is_file($file)) return;
        try {
            $pdo = self::open($file, true);
            $table = (string)$tableNames['player'];
            if (!self::tableExists($pdo, $table)) return;
            $columns = self::columns($pdo, $table);
            if (!in_array('settings.lang', $columns, true)) return;

            $stmt = $pdo->query('SELECT ' . self::qi('settings.lang') . ' AS lang, COUNT(*) AS c FROM ' . self::qi($table) . ' GROUP BY ' . self::qi('settings.lang'));
            $customKnown = [];
            $customUnknown = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $raw = trim((string)($row['lang'] ?? ''));
                $value = strtoupper($raw);
                if ($value === '' || in_array($value, $supported, true)) continue;
                if (in_array($value, $customCore, true)) $customKnown[$value] = (int)$row['c'];
                else $customUnknown[$value] = (int)$row['c'];
            }

            if ($customKnown) {
                $parts = [];
                $total = 0;
                foreach ($customKnown as $lang => $count) { $parts[] = $lang . '=' . $count; $total += $count; }
                $report['language_safety'][] = 'storage/sqlite/clans.db: preserved ' . $total . ' custom player language preference(s) because matching custom core language files were included (' . implode(', ', $parts) . ').';
            }

            if ($customUnknown) {
                $parts = [];
                $total = 0;
                foreach ($customUnknown as $lang => $count) { $parts[] = $lang . '=' . $count; $total += $count; }
                $report['language_safety'][] = 'storage/sqlite/clans.db: preserved ' . $total . ' non-bundled player language preference(s) unchanged (' . implode(', ', $parts) . ').';
                $report['data_validation_warnings'][] = 'Player language preference(s) ' . implode(', ', array_keys($customUnknown)) . ' were preserved, but no matching custom core language YAML was found in the uploaded V8 ZIP. Verify those language files manually.';
            }
        } catch (Throwable $e) {
            $report['data_validation_warnings'][] = 'Player language preservation could not be verified: ' . $e->getMessage();
        }
    }

    private static function validateCoreRelations(string $outputRoot, array $tableNames, array &$report): void
    {
        $file = $outputRoot . '/storage/sqlite/clans.db';
        if (!is_file($file)) return;
        try {
            $pdo = self::open($file, true);
            $clanTable = (string)$tableNames['clan'];
            $playerTable = (string)$tableNames['player'];
            if (!self::tableExists($pdo, $clanTable) || !self::tableExists($pdo, $playerTable)) {
                $report['data_validation_errors'][] = 'Core relational validation could not run because clan/player tables are missing.';
                return;
            }

            $clanColumns = self::columns($pdo, $clanTable);
            $playerColumns = self::columns($pdo, $playerTable);
            foreach (['id', 'leader', 'members'] as $required) {
                if (!in_array($required, $clanColumns, true)) {
                    $report['data_validation_errors'][] = 'Core relational validation missing clan column: ' . $required;
                    return;
                }
            }
            foreach (['uuid', 'name'] as $required) {
                if (!in_array($required, $playerColumns, true)) {
                    $report['data_validation_errors'][] = 'Core relational validation missing player column: ' . $required;
                    return;
                }
            }

            $hasClanId = in_array('clan_id', $playerColumns, true);
            $players = [];
            $duplicatePlayerRows = [];
            $playerSql = 'SELECT ' . self::qi('uuid') . ', ' . self::qi('name') . ($hasClanId ? ', ' . self::qi('clan_id') : '') . ' FROM ' . self::qi($playerTable);
            foreach ($pdo->query($playerSql) as $row) {
                $uuid = trim((string)$row['uuid']);
                if ($uuid === '') continue;
                if (isset($players[$uuid])) $duplicatePlayerRows[$uuid] = true;
                $players[$uuid] = [
                    'name' => (string)($row['name'] ?? ''),
                    'clan_id' => $hasClanId ? trim((string)($row['clan_id'] ?? '')) : '',
                ];
            }

            $clans = [];
            $memberships = [];
            $membershipRefs = 0;
            $leaderProblems = 0;
            $missingPlayers = 0;
            $clanSql = 'SELECT ' . self::qi('id') . ', ' . self::qi('leader') . ', ' . self::qi('members') . (in_array('tag', $clanColumns, true) ? ', ' . self::qi('tag') : '') . ' FROM ' . self::qi($clanTable);
            foreach ($pdo->query($clanSql) as $row) {
                $clanId = trim((string)$row['id']);
                if ($clanId === '') continue;
                $leader = trim((string)($row['leader'] ?? ''));
                $tag = trim((string)($row['tag'] ?? $clanId));
                $members = self::memberList((string)($row['members'] ?? ''));
                $clans[$clanId] = ['leader' => $leader, 'tag' => $tag, 'members' => $members];

                if ($leader === '' || !isset($players[$leader])) {
                    self::appendLimited($report['data_validation_errors'], "Clan {$tag} ({$clanId}) references missing/empty leader player {$leader}.", $leaderProblems);
                } elseif (!in_array($leader, $members, true)) {
                    self::appendLimited($report['data_validation_errors'], "Clan {$tag} ({$clanId}) leader {$players[$leader]['name']} ({$leader}) is not present in clan.members.", $leaderProblems);
                }

                foreach ($members as $uuid) {
                    $membershipRefs++;
                    $memberships[$uuid][] = $clanId;
                    if (!isset($players[$uuid])) {
                        self::appendLimited($report['data_validation_errors'], "Clan {$tag} ({$clanId}) contains member UUID {$uuid}, but no matching player row exists.", $missingPlayers);
                    }
                }
            }

            if ($duplicatePlayerRows) {
                $count = count($duplicatePlayerRows);
                $report['data_validation_errors'][] = "Player table contains {$count} duplicate UUID value(s).";
            }

            $multi = [];
            foreach ($memberships as $uuid => $clanIds) {
                $unique = array_values(array_unique($clanIds));
                if (count($unique) > 1) $multi[$uuid] = $unique;
            }
            if ($multi) {
                $shown = 0;
                foreach ($multi as $uuid => $clanIds) {
                    if ($shown >= 25) break;
                    $name = $players[$uuid]['name'] ?? 'unknown';
                    $labels = [];
                    foreach ($clanIds as $id) $labels[] = ($clans[$id]['tag'] ?? $id) . ' (' . $id . ')';
                    $report['data_validation_errors'][] = "Player {$name} ({$uuid}) belongs to multiple clans: " . implode(', ', $labels) . '.';
                    $shown++;
                }
                if (count($multi) > $shown) $report['data_validation_errors'][] = '... +' . (count($multi) - $shown) . ' additional player(s) with multiple clan memberships.';
            } else {
                $report['data_validation'][] = 'Single-clan membership uniqueness: OK.';
            }

            if ($hasClanId) {
                $memberPlayers = count($memberships);
                $populated = 0;
                $emptyForMembers = 0;
                $mismatches = 0;
                $orphanClanIds = 0;
                foreach ($players as $uuid => $player) {
                    $clanId = $player['clan_id'];
                    if ($clanId !== '') {
                        $populated++;
                        if (!isset($clans[$clanId])) {
                            self::appendLimited($report['data_validation_errors'], "Player {$player['name']} ({$uuid}) has clan_id {$clanId}, but that clan does not exist.", $orphanClanIds);
                        } elseif (!in_array($uuid, $clans[$clanId]['members'], true)) {
                            self::appendLimited($report['data_validation_errors'], "Player {$player['name']} ({$uuid}) has clan_id {$clanId}, but is not listed in that clan's members.", $mismatches);
                        }
                    } elseif (isset($memberships[$uuid])) {
                        $emptyForMembers++;
                    }
                }
                if ($memberPlayers > 0 && $populated === 0) {
                    $report['data_validation_warnings'][] = "All {$memberPlayers} player(s) referenced by clan.members have an empty clan_id. This was not auto-repaired; V9 membership must be verified against the current loader behavior.";
                } elseif ($emptyForMembers > 0) {
                    $report['data_validation_warnings'][] = "{$emptyForMembers} player(s) referenced by clan.members have an empty clan_id.";
                } else {
                    $report['data_validation'][] = 'Player clan_id coverage for clan members: OK.';
                }
            }

            $report['data_validation'][] = 'Core relational validation checked ' . count($clans) . ' clan(s), ' . count($players) . ' player(s) and ' . $membershipRefs . ' clan membership reference(s).';
            if ($leaderProblems === 0 && $missingPlayers === 0) $report['data_validation'][] = 'Clan leaders and members reference existing player rows: OK.';

            if ($leaderProblems > 25) $report['data_validation_errors'][] = '... +' . ($leaderProblems - 25) . ' additional leader relation error(s).';
            if ($missingPlayers > 25) $report['data_validation_errors'][] = '... +' . ($missingPlayers - 25) . ' additional missing-player membership error(s).';
        } catch (Throwable $e) {
            $report['data_validation_errors'][] = 'Core relational validation failed: ' . $e->getMessage();
        }
    }

    private static function memberList(string $value): array
    {
        $parts = preg_split('/[|,;\s]+/', trim($value)) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') $out[$part] = true;
        }
        return array_keys($out);
    }

    private static function appendLimited(array &$bucket, string $message, int &$counter, int $limit = 25): void
    {
        $counter++;
        if ($counter <= $limit) $bucket[] = $message;
    }

    private static function hasAddonSqliteFiles(string $userRoot): bool
    {
        $addonsRoot = $userRoot . '/addons';
        if (!is_dir($addonsRoot)) return false;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($addonsRoot, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'db') continue;
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($userRoot) + 1));
            if (preg_match('#^addons/[^/]+/storage/sqlite/[^/]+\.db$#', $rel)) return true;
        }
        return false;
    }

    private static function migrateAddonDatabases(string $userRoot, string $outputRoot, string $templateRoot, array &$report): void
    {
        $addonsRoot = $userRoot . '/addons';
        if (!is_dir($addonsRoot)) return;

        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($addonsRoot, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'db') continue;
            $relFromUser = str_replace('\\', '/', substr($file->getPathname(), strlen($userRoot) + 1));
            if (!preg_match('#^addons/([^/]+)/storage/sqlite/[^/]+\.db$#', $relFromUser, $m)) continue;

            $addon = $m[1];
            $addonStorage = self::addonStorageSettings($userRoot, $addon);
            if (($addonStorage['type'] ?? null) !== null && ($addonStorage['type'] ?? null) !== 'sqlite') {
                $report['storage_warnings'][] = $relFromUser . ' exists, but addon ' . $addon . ' is configured for ' . $addonStorage['type'] . ' storage; this SQLite file was treated as inactive/stale.';
                continue;
            }
            if (!is_dir($outputRoot . '/addons/' . $addon)) {
                $report['storage_warnings'][] = $relFromUser . ' was skipped because addon ' . $addon . ' has no selected V9 output.';
                continue;
            }

            $template = $templateRoot . '/' . $relFromUser;
            if (!is_file($template)) {
                try {
                    $sourceWithoutTemplate = self::open($file->getPathname(), true);
                    $sourceIntegrity = self::integrity($sourceWithoutTemplate);
                    if ($sourceIntegrity !== 'ok') {
                        $report['storage_errors'][] = $relFromUser . ' failed source PRAGMA integrity_check: ' . $sourceIntegrity;
                        continue;
                    }
                    $rows = self::databaseRowCount($sourceWithoutTemplate);
                    if ($addon === 'ClanGladiator' && $rows > 0 && is_dir($outputRoot . '/addons/ClanGladiator')) {
                        $report['unmapped'][] = $relFromUser . ': active V8 ClanGladiator SQLite data detected (' . $rows . ' row(s)). Current V9 Gladiator uses redesigned YAML storage, so arena/schedule data was not guessed or copied raw; review/recreate the V9 arena data.';
                    } elseif ($rows > 0) {
                        $report['unmapped'][] = $relFromUser . ': ' . $rows . ' active SQLite row(s) detected, but no compatible V9 SQLite template exists. Data was not copied raw.';
                    } else {
                        $report['storage_warnings'][] = $relFromUser . ' has no V9 SQLite template; the source database is empty and was not copied raw.';
                    }
                } catch (Throwable $e) {
                    $report['storage_errors'][] = $relFromUser . ': could not inspect source database without V9 template: ' . $e->getMessage();
                }
                continue;
            }

            try {
                $source = self::open($file->getPathname(), true);
                $sourceIntegrity = self::integrity($source);
                if ($sourceIntegrity !== 'ok') {
                    $report['storage_errors'][] = $relFromUser . ' failed source PRAGMA integrity_check: ' . $sourceIntegrity;
                    continue;
                }

                $destFile = $outputRoot . '/' . $relFromUser;
                @mkdir(dirname($destFile), 0700, true);
                if (!copy($template, $destFile)) {
                    $report['storage_errors'][] = $relFromUser . ': could not create destination database.';
                    continue;
                }
                $dest = self::open($destFile, false);
                $tables = self::tables($dest);
                if (!$tables) {
                    $report['storage_warnings'][] = $relFromUser . ': V9 database template has no user tables.';
                    continue;
                }

                foreach ($tables as $templateTable) {
                    $configuredTable = (string)($addonStorage['tables'][$templateTable] ?? $templateTable);
                    if (!self::tableExists($source, $configuredTable)) {
                        $report['storage_warnings'][] = $relFromUser . ': source table ' . $configuredTable . ' was not found; destination table was left empty.';
                        continue;
                    }
                    if ($configuredTable !== $templateTable) {
                        if (self::tableExists($dest, $configuredTable)) $dest->exec('DROP TABLE ' . self::qi($configuredTable));
                        $dest->exec('ALTER TABLE ' . self::qi($templateTable) . ' RENAME TO ' . self::qi($configuredTable));
                        $report['storage'][] = $relFromUser . ': V9 table ' . $templateTable . ' renamed to configured table ' . $configuredTable;
                    }
                    self::copyTable($source, $dest, $configuredTable, $configuredTable, $relFromUser, $report);
                }

                $integrity = self::integrity($dest);
                if ($integrity === 'ok') $report['storage'][] = $relFromUser . ' integrity: OK';
                else $report['storage_errors'][] = $relFromUser . ' failed destination PRAGMA integrity_check: ' . $integrity;
            } catch (Throwable $e) {
                $report['storage_errors'][] = $relFromUser . ': ' . $e->getMessage();
            }
        }
    }

    private static function copyTable(PDO $source, PDO $dest, string $sourceTable, string $destinationTable, string $destinationLabel, array &$report): void
    {
        $sourceColumns = self::columns($source, $sourceTable);
        $destColumns = self::columns($dest, $destinationTable);
        $common = array_values(array_intersect($destColumns, $sourceColumns));
        if (!$common) {
            $report['storage_errors'][] = $destinationLabel . ': table ' . $sourceTable . ' -> ' . $destinationTable . ' has no compatible columns.';
            return;
        }

        $sourceCount = (int)$source->query('SELECT COUNT(*) FROM ' . self::qi($sourceTable))->fetchColumn();
        $quotedColumns = implode(', ', array_map([self::class, 'qi'], $common));
        $placeholders = implode(', ', array_fill(0, count($common), '?'));

        $dest->beginTransaction();
        try {
            $dest->exec('DELETE FROM ' . self::qi($destinationTable));
            $select = $source->query('SELECT ' . $quotedColumns . ' FROM ' . self::qi($sourceTable));
            $insert = $dest->prepare('INSERT INTO ' . self::qi($destinationTable) . ' (' . $quotedColumns . ') VALUES (' . $placeholders . ')');
            while (($row = $select->fetch(PDO::FETCH_NUM)) !== false) $insert->execute($row);
            $dest->commit();
        } catch (Throwable $e) {
            if ($dest->inTransaction()) $dest->rollBack();
            throw $e;
        }

        $destCount = (int)$dest->query('SELECT COUNT(*) FROM ' . self::qi($destinationTable))->fetchColumn();
        if ($destCount !== $sourceCount) {
            $report['storage_errors'][] = $destinationLabel . ': ' . $sourceTable . ' -> ' . $destinationTable . ' row count mismatch (' . $sourceCount . ' source / ' . $destCount . ' destination).';
            return;
        }

        $extraDest = array_values(array_diff($destColumns, $sourceColumns));
        $suffix = $extraDest ? ' (new V9 columns kept at template/default values: ' . implode(', ', $extraDest) . ')' : '';
        $tableLabel = $sourceTable === $destinationTable ? $sourceTable : ($sourceTable . ' -> ' . $destinationTable);
        $report['storage'][] = $destinationLabel . ': ' . $tableLabel . ' — ' . $sourceCount . '/' . $destCount . ' rows migrated' . $suffix;
    }

    private static function addonStorageSettings(string $userRoot, string $addon): array
    {
        $rules = [
            'ClanNexus' => ['type' => 'Storage.type', 'tables' => [
                'uclans-nexus-clans' => 'Storage.databases.clan_nexus_table',
                'uclans-nexus-terrains' => 'Storage.databases.nexus_terrains_table',
            ]],
            'ClanLeague' => ['type' => 'Storage.type', 'tables' => [
                'uclans-league-points' => 'Storage.databases.league_table',
                'uclans-league-season' => 'Storage.databases.season_table',
            ]],
            'ClanLand' => ['type' => 'Config.Storage.type', 'tables' => [
                'uclans-land' => 'Config.Storage.databases.lands_table',
            ]],
            'ClanQuest' => ['type' => 'Storage.type', 'tables' => [
                'uclans-quest' => 'Storage.databases.quest_table',
            ]],
            'ClanLeaderboard' => ['type' => 'Config.Storage.type', 'tables' => [
                'uclans-leaderboard_loc' => 'Config.Storage.databases.leaderboard_loc',
            ]],
            'ClanChest' => ['type' => 'Storage.type', 'tables' => [
                'uclans_chest-items' => 'Storage.databases.chest_items_table',
                'uclans_chest-info' => 'Storage.databases.chest_info_table',
            ]],
            // V8 Gladiator can use SQLite, while the current V9 Gladiator uses
            // redesigned YAML persistence. The type is still read so a stale
            // SQLite file is not mistaken for active data.
            'ClanGladiator' => ['type' => 'Storage.type', 'tables' => []],
        ];
        if (!isset($rules[$addon])) return ['type' => null, 'tables' => []];
        $file = $userRoot . '/addons/' . $addon . '/config.yml';
        if (!is_file($file) || !class_exists('YamlDocument')) return ['type' => null, 'tables' => []];
        try {
            $doc = YamlDocument::fromFile($file);
            $type = self::yamlScalar($doc, $rules[$addon]['type']);
            $out = ['type' => $type !== null ? strtolower($type) : null, 'tables' => []];
            foreach ($rules[$addon]['tables'] as $defaultTable => $path) {
                $value = self::yamlScalar($doc, $path);
                if ($value !== null && preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $value)) $out['tables'][$defaultTable] = $value;
            }
            return $out;
        } catch (Throwable) { return ['type' => null, 'tables' => []]; }
    }

    private static function yamlScalar(YamlDocument $doc, string $path): ?string
    {
        $node = $doc->node($path);
        if (!$node) return null;
        $value = trim((string)$node['raw_value'], " \t\n\r\0\x0B'\"");
        return $value !== '' ? $value : null;
    }

    private static function storageType(string $userRoot): ?string
    {
        $file = $userRoot . '/storage.yml';
        if (!is_file($file) || !class_exists('YamlDocument')) return null;
        try {
            $doc = YamlDocument::fromFile($file);
            $node = $doc->node('Storage.type');
            if (!$node) return null;
            $value = strtolower(trim((string)$node['raw_value'], " \t\n\r\0\x0B'\""));
            return $value !== '' ? $value : null;
        } catch (Throwable) { return null; }
    }

    private static function coreTableNames(string $userRoot): array
    {
        $defaults = [
            'clan' => 'uclans-clans', 'player' => 'uclans-players', 'invite' => 'uclans-invite',
            'moderation' => 'uclans-moderation', 'logger' => 'uclans-logger', 'mail' => 'uclans-mail', 'rewards' => 'uclans-rewards',
        ];
        $file = $userRoot . '/storage.yml';
        if (!is_file($file) || !class_exists('YamlDocument')) return $defaults;
        try {
            $doc = YamlDocument::fromFile($file);
            $paths = [
                'clan' => 'Storage.databases.clan_table', 'player' => 'Storage.databases.player_table',
                'invite' => 'Storage.databases.invite_table', 'moderation' => 'Storage.databases.moderation_table',
                'logger' => 'Storage.databases.logger_table', 'mail' => 'Storage.databases.mail_table',
                'rewards' => 'Storage.databases.rewards_table',
            ];
            foreach ($paths as $key => $path) {
                $node = $doc->node($path);
                if (!$node) continue;
                $value = trim((string)$node['raw_value'], " \t\n\r\0\x0B'\"");
                if ($value !== '' && preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $value)) $defaults[$key] = $value;
            }
        } catch (Throwable) {}
        return $defaults;
    }

    private static function open(string $file, bool $source): PDO
    {
        $pdo = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout=5000');
        if (!$source) {
            $pdo->exec('PRAGMA foreign_keys=OFF');
            $pdo->exec('PRAGMA synchronous=OFF');
        }
        return $pdo;
    }

    private static function integrity(PDO $pdo): string
    {
        $value = $pdo->query('PRAGMA integrity_check')->fetchColumn();
        return strtolower(trim((string)$value));
    }

    private static function tables(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        return array_values(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
    }

    private static function databaseRowCount(PDO $pdo): int
    {
        $total = 0;
        foreach (self::tables($pdo) as $table) {
            try { $total += (int)$pdo->query('SELECT COUNT(*) FROM ' . self::qi($table))->fetchColumn(); }
            catch (Throwable) { /* inspection only; the caller still reports the DB as unmapped */ }
        }
        return $total;
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ? LIMIT 1");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }

    private static function columns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->query('PRAGMA table_info(' . self::qi($table) . ')');
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $out[] = (string)$row['name'];
        return $out;
    }

    private static function qi(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
