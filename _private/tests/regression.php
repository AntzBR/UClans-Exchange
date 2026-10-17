<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$base = dirname(__DIR__);
require_once $base . '/lib/YamlDocument.php';
require_once $base . '/lib/SmartPreservationPolicy.php';
require_once $base . '/lib/FinalValidator.php';
require_once $base . '/lib/CompatibilityPolicy.php';
require_once $base . '/lib/Converter.php';

$passed = 0;
$failed = 0;
$skipped = 0;

function ok(bool $condition, string $label): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[PASS] {$label}\n";
    } else {
        $failed++;
        echo "[FAIL] {$label}\n";
    }
}

function skip_case(string $label, string $why): void
{
    global $skipped;
    $skipped++;
    echo "[SKIP] {$label} — {$why}\n";
}

function copy_tree(string $src, string $dst): void
{
    if (!is_dir($dst)) mkdir($dst, 0700, true);
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $rel = substr($item->getPathname(), strlen(rtrim($src, '/\\')) + 1);
        $target = $dst . '/' . str_replace('\\', '/', $rel);
        if ($item->isDir()) {
            if (!is_dir($target)) mkdir($target, 0700, true);
        } else {
            if (!is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
            copy($item->getPathname(), $target);
        }
    }
}

function remove_tree(string $dir): void
{
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    @rmdir($dir);
}

function policy_case(string $file, string $path, string $sourceYaml, string $destYaml): array
{
    $src = new YamlDocument($sourceYaml);
    $dst = new YamlDocument($destYaml);
    return SmartPreservationPolicy::classify($file, $path, $src->node($path), $dst->node($path));
}

echo "UltimateClans Exchange V15.1 regression suite\n";
echo str_repeat('=', 46) . "\n";

// Real version-distance cases already seen in migration reports.
ok(CompatibilityPolicy::mode('8.15.7', '8.21.0', 6) === 'SAFE_CONSERVATIVE', '8.15.7 uses SAFE_CONSERVATIVE against 8.21.0');
ok(CompatibilityPolicy::mode('8.2.1', '8.21.0', 6) === 'SAFE_CONSERVATIVE', '8.2.1 uses SAFE_CONSERVATIVE against 8.21.0');
ok(CompatibilityPolicy::mode('8.16.9', '8.21.0', 6) === 'NORMAL', '8.16.9 remains NORMAL at the configured compatibility threshold');


// V15.1 targets the currently installed V9.1 template set.
$v9TemplateConfig = YamlDocument::fromFile($base . '/templates/v9/config.yml');
ok(trim((string)$v9TemplateConfig->canonicalValue('version.generated'), "'\"") === '9.1.0', 'target template is UltimateClans V9.1.0');
ok(is_file($base . '/templates/v9/admin_gui.yml'), 'V9.1 admin_gui.yml is included in the target template');
ok(is_file($base . '/templates/v9/addons/ClanGladiator/config.yml'), 'current V9 ClanGladiator template is available');

// V14.1 legacy-root detection: valid V8 packages must not depend solely on
// version.generated, and their files may sit directly at the ZIP root or in a
// nested plugin directory.
$detectCfg = require $base . '/config.php';
$detectConverter = new UltimateClansConverter($detectCfg);
$detectRef = new ReflectionClass($detectConverter);
$detectMethod = $detectRef->getMethod('detectV8Root');
$detectMethod->setAccessible(true);
$legacyProp = $detectRef->getProperty('legacyStructureDetected');
$legacyProp->setAccessible(true);

$legacyRootCase = sys_get_temp_dir() . '/uclans-exchange-v15-root-' . bin2hex(random_bytes(5));
copy_tree($base . '/templates/v8', $legacyRootCase);
$legacyConfig = file_get_contents($legacyRootCase . '/config.yml');
$legacyConfig = preg_replace('/\n# DO NOT EDIT THIS MANUALLY!\s*\nversion:\s*\n\s+generated:.*?\s*$/s', "\n", $legacyConfig, 1);
file_put_contents($legacyRootCase . '/config.yml', $legacyConfig);
$detectedRoot = $detectMethod->invoke($detectConverter, $legacyRootCase);
ok(realpath((string)$detectedRoot) === realpath($legacyRootCase), 'legacy V8 without version.generated is detected at archive root');
ok($legacyProp->getValue($detectConverter) === true, 'legacy structural detection is flagged for forced SAFE_CONSERVATIVE mode');

// An addon can have its own config/version. It must never outrank the core
// installation when the core is being recognized structurally.
@mkdir($legacyRootCase . '/addons/TestAddon', 0700, true);
file_put_contents($legacyRootCase . '/addons/TestAddon/config.yml', "Plugin:\n  version: 8.9.0\nConfig:\n  enabled: true\n");
$legacyProp->setValue($detectConverter, false);
$detectedWithAddon = $detectMethod->invoke($detectConverter, $legacyRootCase);
ok(realpath((string)$detectedWithAddon) === realpath($legacyRootCase), 'addon config with an 8.x version cannot outrank the UltimateClans core root');
remove_tree($legacyRootCase);

$nestedCase = sys_get_temp_dir() . '/uclans-exchange-v15-nested-' . bin2hex(random_bytes(5));
$nestedPlugin = $nestedCase . '/backup/plugins/UltimateClans';
copy_tree($base . '/templates/v8', $nestedPlugin);
$nestedConfig = file_get_contents($nestedPlugin . '/config.yml');
$nestedConfig = preg_replace('/\n# DO NOT EDIT THIS MANUALLY!\s*\nversion:\s*\n\s+generated:.*?\s*$/s', "\n", $nestedConfig, 1);
file_put_contents($nestedPlugin . '/config.yml', $nestedConfig);
$legacyProp->setValue($detectConverter, false);
$detectedNested = $detectMethod->invoke($detectConverter, $nestedCase);
ok(realpath((string)$detectedNested) === realpath($nestedPlugin), 'legacy V8 is detected inside a nested plugin directory');
remove_tree($nestedCase);

$v9Case = sys_get_temp_dir() . '/uclans-exchange-v15-v9-' . bin2hex(random_bytes(5));
copy_tree($base . '/templates/v9', $v9Case);
// Even if legacy leftovers exist, an explicit V9 version must win and be rejected.
file_put_contents($v9Case . '/gui2.yml', "General:\n  test: true\n");
file_put_contents($v9Case . '/level.yml', "Level:\n  test: true\n");
$v9Rejected = false;
try {
    $legacyProp->setValue($detectConverter, false);
    $detectMethod->invoke($detectConverter, $v9Case);
} catch (Throwable) {
    $v9Rejected = true;
}
ok($v9Rejected, 'explicit V9 config is not accepted through legacy V8 fallback');
remove_tree($v9Case);

$unrelatedCase = sys_get_temp_dir() . '/uclans-exchange-v15-unrelated-' . bin2hex(random_bytes(5));
mkdir($unrelatedCase, 0700, true);
file_put_contents($unrelatedCase . '/config.yml', "Config:\n  language: EN\nClan:\n  tag: test\n");
$unrelatedRejected = false;
try {
    $detectMethod->invoke($detectConverter, $unrelatedCase);
} catch (Throwable) {
    $unrelatedRejected = true;
}
ok($unrelatedRejected, 'arbitrary config.yml is not mistaken for UltimateClans V8');
remove_tree($unrelatedCase);

// Smart preservation: user-facing values should survive safe mode.
$d = policy_case(
    'commands.yml',
    'Commands.command_aliases.create',
    "Commands:\n  command_aliases:\n    create: criar\n",
    "Commands:\n  command_aliases:\n    create: create\n"
);
ok($d['preserve'] === true && $d['category'] === 'command_alias', 'custom command alias is smart-preserved');

$d = policy_case(
    'gui.yml',
    'General.clan_home.items.test.slot',
    "General:\n  clan_home:\n    items:\n      test:\n        slot: 22\n",
    "General:\n  clan_home:\n    items:\n      test:\n        slot: 10\n"
);
ok($d['preserve'] === true && $d['category'] === 'gui_item', 'GUI slot is smart-preserved');

$d = policy_case(
    'config.yml',
    'Clan.tag.default_color',
    "Clan:\n  tag:\n    default_color: '<red>'\n",
    "Clan:\n  tag:\n    default_color: '<gray>'\n"
);
ok($d['preserve'] === true, 'stable clan tag color is smart-preserved');

// Functionally/version-sensitive values stay held.
$d = policy_case(
    'chat.yml',
    'Chat.config.priority',
    "Chat:\n  config:\n    priority: HIGH\n",
    "Chat:\n  config:\n    priority: NORMAL\n"
);
ok($d['preserve'] === false, 'chat event priority remains held in safe mode');

$d = policy_case(
    'moderation.yml',
    'Moderation.list.member.default_permissions',
    "Moderation:\n  list:\n    member:\n      default_permissions:\n      - DEFAULT:true\n      - KICK:true\n",
    "Moderation:\n  list:\n    member:\n      default_permissions:\n      - DEFAULT:true\n"
);
ok($d['preserve'] === false, 'role default_permissions remains held in safe mode');

// Integrated safe-mode migration: verified user-facing values are applied while
// a sensitive same-path value remains at the clean V9 default.
$integrated = sys_get_temp_dir() . '/uclans-exchange-v14-integrated-' . bin2hex(random_bytes(5));
$userV8 = $integrated . '/user';
$outV9 = $integrated . '/out';
copy_tree($base . '/templates/v8', $userV8);
copy_tree($base . '/templates/v9', $outV9);

$commands = file_get_contents($userV8 . '/commands.yml');
$commands = preg_replace('/(^\s*create:\s*)create\s*$/m', '$1criar', $commands, 1);
file_put_contents($userV8 . '/commands.yml', $commands);

$configText = file_get_contents($userV8 . '/config.yml');
$configText = preg_replace('/(^\s*default_color:\s*).+$/m', "$1'<red>'", $configText, 1);
file_put_contents($userV8 . '/config.yml', $configText);

$chatText = file_get_contents($userV8 . '/chat.yml');
$chatText = preg_replace('/(^\s*priority:\s*).+$/m', '$1HIGH', $chatText, 1);
file_put_contents($userV8 . '/chat.yml', $chatText);

$cfg = require $base . '/config.php';
$converter = new UltimateClansConverter($cfg);
$ref = new ReflectionClass($converter);
$empty = $ref->getMethod('emptyReport');
$empty->setAccessible(true);
$initialReport = $empty->invoke($converter);
foreach ([
    'report' => $initialReport,
    'conservativeMode' => true,
    'sourceVersion' => '8.2.1',
    'referenceVersion' => '8.21.0',
    'docCache' => [],
    'ops' => [],
    'legacyRoles' => [],
] as $property => $value) {
    $rp = $ref->getProperty($property);
    $rp->setAccessible(true);
    $rp->setValue($converter, $value);
}
$migrate = $ref->getMethod('migrate');
$migrate->setAccessible(true);
$migrate->invoke($converter, $userV8, $outV9);

$outCommands = YamlDocument::fromFile($outV9 . '/commands.yml');
$outConfig = YamlDocument::fromFile($outV9 . '/config.yml');
$outChat = YamlDocument::fromFile($outV9 . '/chat.yml');
ok(trim((string)$outCommands->canonicalValue('Commands.command_aliases.create'), "'\"") === 'criar', 'integrated safe mode preserves custom command alias');
ok(str_contains((string)$outConfig->canonicalValue('Clan.tag.default_color'), '<red>'), 'integrated safe mode preserves stable clan tag color');
ok(trim((string)$outChat->canonicalValue('Chat.config.priority'), "'\"") !== 'HIGH', 'integrated safe mode holds sensitive Chat.config.priority');

$reportProp = $ref->getProperty('report');
$reportProp->setAccessible(true);
$integratedReport = $reportProp->getValue($converter);
ok(count($integratedReport['smart_preserved'] ?? []) >= 2 && count($integratedReport['conservative'] ?? []) >= 1, 'integrated report separates smart-preserved and held values');

$statusMethod = $ref->getMethod('calculateFinalStatus');
$statusMethod->setAccessible(true);
ok($statusMethod->invoke($converter, []) === 'SAFE', 'final status SAFE for a clean report');
ok($statusMethod->invoke($converter, ['conservative' => ['held']]) === 'SAFE_WITH_CHANGES', 'final status SAFE_WITH_CHANGES for held safe-mode values');
ok($statusMethod->invoke($converter, ['unmapped' => ['review']]) === 'REVIEW_REQUIRED', 'final status REVIEW_REQUIRED for unresolved migration choices');
ok($statusMethod->invoke($converter, ['data_validation_errors' => ['bad relation']]) === 'UNSAFE_DATA', 'final status UNSAFE_DATA for relational errors');
remove_tree($integrated);

// Dynamic ClanQuest records are data IDs, not fixed schema keys. A custom
// quest whose internal shape is known by V9 must migrate as one subtree without
// generating one manual-review item per field.
$questCase = sys_get_temp_dir() . '/uclans-exchange-v15-quest-' . bin2hex(random_bytes(5));
$questUser = $questCase . '/user';
$questOut = $questCase . '/out';
copy_tree($base . '/templates/v8', $questUser);
copy_tree($base . '/templates/v9', $questOut);
file_put_contents($questUser . '/addons/ClanQuest/quests.yml', "\n  custom_shearer:\n    status: true\n    type: DAILY\n    item:\n      click_sound: 'block.enchantment_table.use'\n      material: SHEARS\n      name: '<green>Custom Shearer'\n      glow: false\n      amount: 1\n      texture: ''\n      lore:\n      - '<gray>Custom quest'\n    missions:\n      SHEAR:\n        amount: 20\n    rewards:\n      commands: []\n      points: 10\n      exp: 5\n      league: 0\n    messages:\n    - '<green>Done'\n", FILE_APPEND);

$questConverter = new UltimateClansConverter($cfg);
$questRef = new ReflectionClass($questConverter);
$questEmpty = $questRef->getMethod('emptyReport');
$questEmpty->setAccessible(true);
foreach ([
    'report' => $questEmpty->invoke($questConverter),
    'conservativeMode' => false,
    'sourceVersion' => '8.16.1',
    'referenceVersion' => '8.21.0',
    'docCache' => [],
    'ops' => [],
    'legacyRoles' => [],
] as $property => $value) {
    $rp = $questRef->getProperty($property);
    $rp->setAccessible(true);
    $rp->setValue($questConverter, $value);
}
$questMigrate = $questRef->getMethod('migrate');
$questMigrate->setAccessible(true);
$questMigrate->invoke($questConverter, $questUser, $questOut);
$questDoc = YamlDocument::fromFile($questOut . '/addons/ClanQuest/quests.yml');
ok($questDoc->has('Quest.custom_shearer.status'), 'custom ClanQuest record is appended to the V9 quest tree');
ok((string)$questDoc->canonicalValue('Quest.custom_shearer.missions.SHEAR.amount') === '20', 'custom ClanQuest mission data is preserved');
$questReportProp = $questRef->getProperty('report');
$questReportProp->setAccessible(true);
$questReport = $questReportProp->getValue($questConverter);
$questManual = array_filter($questReport['unmapped'] ?? [], static fn($v) => str_contains((string)$v, 'Quest.custom_shearer'));
$questCompat = array_filter($questReport['compatibility'] ?? [], static fn($v) => str_contains((string)$v, 'custom quest subtree'));
ok(count($questManual) === 0, 'compatible custom ClanQuest record does not generate per-field manual reviews');
ok(count($questCompat) >= 1, 'custom ClanQuest subtree preservation is summarized in the report');
remove_tree($questCase);

// V15.1: V9.1 still supports dynamic gui.yml Insertion records. Preserve the
// complete user subtree instead of reporting every inserted item field.
$insertionCase = sys_get_temp_dir() . '/uclans-exchange-v151-insertion-' . bin2hex(random_bytes(5));
$insertionUser = $insertionCase . '/user';
$insertionOut = $insertionCase . '/out';
copy_tree($base . '/templates/v8', $insertionUser);
copy_tree($base . '/templates/v9', $insertionOut);
$insertionGui = file_get_contents($insertionUser . '/gui.yml');
$insertionGui = preg_replace(
    '/^Insertion:\s*\[\s*\n\s*\]\s*$/m',
    "Insertion:\n  General:\n    player_profile:\n      items:\n        custom_button:\n          material: STONE\n          amount: 1\n          name: '<green>Custom'\n          glow: false\n          slot: 3",
    $insertionGui,
    1
);
file_put_contents($insertionUser . '/gui.yml', $insertionGui);
$insertionConverter = new UltimateClansConverter($cfg);
$insertionRef = new ReflectionClass($insertionConverter);
$insertionEmpty = $insertionRef->getMethod('emptyReport');
$insertionEmpty->setAccessible(true);
foreach ([
    'report' => $insertionEmpty->invoke($insertionConverter),
    'conservativeMode' => true,
    'sourceVersion' => '8.8.10',
    'referenceVersion' => '8.21.0',
    'docCache' => [],
    'ops' => [],
    'legacyRoles' => [],
] as $property => $value) {
    $rp = $insertionRef->getProperty($property);
    $rp->setAccessible(true);
    $rp->setValue($insertionConverter, $value);
}
$insertionMigrate = $insertionRef->getMethod('migrate');
$insertionMigrate->setAccessible(true);
$insertionMigrate->invoke($insertionConverter, $insertionUser, $insertionOut);
$insertionDoc = YamlDocument::fromFile($insertionOut . '/gui.yml');
ok($insertionDoc->has('Insertion.General.player_profile.items.custom_button.material'), 'dynamic GUI Insertion subtree is preserved');
$insertionReportProp = $insertionRef->getProperty('report');
$insertionReportProp->setAccessible(true);
$insertionReport = $insertionReportProp->getValue($insertionConverter);
$insertionManual = array_filter($insertionReport['unmapped'] ?? [], static fn($v) => str_contains((string)$v, 'Insertion.'));
ok(count($insertionManual) === 0, 'dynamic GUI Insertion does not generate per-field manual reviews');
remove_tree($insertionCase);

// V15.1: command presentation blocks removed by V9 are obsolete, not migration
// decisions. The one surviving disband.sound.on_error setting must remain usable.
$ignored = MigrationRules::ignoredPrefixes();
ok(in_array('commands.yml|Commands.list.clan.create.title', $ignored, true), 'legacy create.title presentation block is classified obsolete');
ok(in_array('commands.yml|Commands.list.clan.ff.sound', $ignored, true), 'legacy ff.sound presentation block is classified obsolete');
ok(!in_array('commands.yml|Commands.list.clan.disband.sound.on_error', $ignored, true), 'V9-supported disband.sound.on_error is not ignored');

// V15.1: clean module YAML templates use an empty list placeholder, but the
// current V9 Logger/Mail/Rewards runtime serializers persist data.<id> maps.
$yamlCase = sys_get_temp_dir() . '/uclans-exchange-v151-yaml-runtime-' . bin2hex(random_bytes(5));
$yamlUser = $yamlCase . '/user';
$yamlOut = $yamlCase . '/out';
@mkdir($yamlUser . '/storage/yaml', 0700, true);
copy_tree($base . '/templates/v9', $yamlOut);
file_put_contents($yamlUser . '/storage.yml', "Storage:\n  type: yaml\n");
file_put_contents($yamlUser . '/storage/yaml/clans.yml', "data: {}\n");
file_put_contents($yamlUser . '/storage/yaml/logger.yml', "data:\n  logger-1:\n    clan_id: clan-1\n    text: '<yellow>Created'\n    plugin: Clan\n    date: 123\n");
file_put_contents($yamlUser . '/storage/yaml/mail.yml', "data:\n  mail-1:\n    from: Player\n    text: Hello\n    to: clan-1\n    sender: player-1\n    date: 123\n    read_by: []\n");
file_put_contents($yamlUser . '/storage/yaml/rewards.yml', "data:\n  reward-1:\n    id: reward-1\n    clan_id: clan-1\n    sender: player-1\n    rewards:\n    - /eco give %player% 1\n    collected_by: []\n    description: Test\n    icon: DIAMOND\n    date: 123\n");
$yamlReport = [];
YamlStorageMigrator::migrate($yamlUser, $yamlOut, $base . '/templates/yaml-v9', $yamlReport);
ok(count($yamlReport['yaml_storage_errors'] ?? []) === 0, 'verified Logger/Mail/Rewards runtime map serializers do not trigger false YAML errors');
$loggerOut = YamlDocument::fromFile($yamlOut . '/modules/Logger/storage/yaml/logger.yml');
$mailOut = YamlDocument::fromFile($yamlOut . '/modules/Mail/storage/yaml/mail.yml');
$rewardsOut = YamlDocument::fromFile($yamlOut . '/modules/Rewards/storage/yaml/rewards.yml');
ok($loggerOut->has('data.logger-1.clan_id'), 'Logger YAML map record survives V9 empty-list template placeholder');
ok($mailOut->has('data.mail-1.text'), 'Mail YAML map record survives V9 empty-list template placeholder');
ok($rewardsOut->has('data.reward-1.rewards'), 'Rewards YAML map record survives V9 empty-list template placeholder');
remove_tree($yamlCase);

// Final validator on a clean V9 template must be quiet.
$tmp = sys_get_temp_dir() . '/uclans-exchange-v14-' . bin2hex(random_bytes(5));
copy_tree($base . '/templates/v9', $tmp);
$report = [];
FinalValidator::validate($tmp, $report, ['EN','PL','TR','RU','ES','HU','GE','FR','BR'], $base . '/templates/v9');
ok(count($report['final_validation_errors'] ?? []) === 0, 'clean V9 template has no final-validator errors');
ok(count($report['final_validation_warnings'] ?? []) === 0, 'clean V9 template has no final-validator warnings');

// Custom languages are valid user data and must not be treated as invalid.
$config = file_get_contents($tmp . '/config.yml');
$config = preg_replace('/(^\s*language:\s*)EN\s*$/m', '$1Vi', $config, 1);
file_put_contents($tmp . '/config.yml', $config);
@mkdir($tmp . '/language', 0700, true);
file_put_contents($tmp . '/language/Lang_Vi.yml', "Lang:\n  test: 'Xin chao'\n");
$report = [];
FinalValidator::validate($tmp, $report, ['EN','PL','TR','RU','ES','HU','GE','FR','BR'], $base . '/templates/v9');
$langWarnings = array_filter($report['final_validation_warnings'] ?? [], static fn($v) => str_contains(strtolower((string)$v), 'language'));
ok(count($langWarnings) === 0, 'custom selected language Vi is accepted when its file is preserved');

// Alias collisions are surfaced without corrupting the package.
$commands = file_get_contents($tmp . '/commands.yml');
$commands = preg_replace('/(^\s*view:\s*)view\s*$/m', '$1list', $commands, 1);
file_put_contents($tmp . '/commands.yml', $commands);
$report = [];
FinalValidator::validate($tmp, $report, ['EN','PL','TR','RU','ES','HU','GE','FR','BR'], $base . '/templates/v9');
$aliasWarnings = array_filter($report['final_validation_warnings'] ?? [], static fn($v) => str_contains(strtolower((string)$v), 'alias collision'));
ok(count($aliasWarnings) >= 1, 'duplicate command alias is reported by the final validator');

// Forbidden runtime/binary files must be detected if sanitization ever regresses.
file_put_contents($tmp . '/bad.jar', 'not-a-real-jar');
$report = [];
FinalValidator::validate($tmp, $report, ['EN','PL','TR','RU','ES','HU','GE','FR','BR'], $base . '/templates/v9');
$packageErrors = array_filter($report['final_validation_errors'] ?? [], static fn($v) => str_contains(strtolower((string)$v), 'forbidden runtime/binary'));
ok(count($packageErrors) >= 1, 'forbidden JAR is detected by the final validator');

remove_tree($tmp);

if (class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    // Addon SQLite must migrate even when the core has no storage/sqlite/clans.db.
    $sqliteCase = sys_get_temp_dir() . '/uclans-exchange-v15-addon-sqlite-' . bin2hex(random_bytes(5));
    $sqliteUser = $sqliteCase . '/user';
    $sqliteOut = $sqliteCase . '/out';
    @mkdir($sqliteUser . '/addons/ClanQuest/storage/sqlite', 0700, true);
    @mkdir($sqliteOut . '/addons/ClanQuest', 0700, true);
    file_put_contents($sqliteUser . '/addons/ClanQuest/config.yml', "Storage:\n  type: sqlite\n  databases:\n    quest_table: uclans-quest\n");
    $sourceQuestDb = $sqliteUser . '/addons/ClanQuest/storage/sqlite/quest.db';
    copy($base . '/templates/sqlite-v9/addons/ClanQuest/storage/sqlite/quest.db', $sourceQuestDb);
    $sourcePdo = new PDO('sqlite:' . $sourceQuestDb);
    $sourcePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sourcePdo->exec("DELETE FROM \"uclans-quest\"");
    $sourcePdo->exec("INSERT INTO \"uclans-quest\" (id, clanid, quest, start, status, player, earned) VALUES ('fixture-1','clan-1','custom_shearer',123,'DONE','player-1',10)");
    $sourcePdo = null;
    $sqliteReport = [];
    SqliteMigrator::migrate($sqliteUser, $sqliteOut, $base . '/templates/sqlite-v9', $sqliteReport, []);
    $destQuestDb = $sqliteOut . '/addons/ClanQuest/storage/sqlite/quest.db';
    ok(is_file($destQuestDb), 'addon SQLite migrates without a core clans.db');
    if (is_file($destQuestDb)) {
        $destPdo = new PDO('sqlite:' . $destQuestDb);
        $rows = (int)$destPdo->query('SELECT COUNT(*) FROM "uclans-quest"')->fetchColumn();
        ok($rows === 1, 'independent ClanQuest SQLite migration preserves row count');
        $destPdo = null;
    }
    remove_tree($sqliteCase);
} else {
    skip_case('PDO_SQLite integration', 'pdo_sqlite is not installed in this CLI environment');
}

if (class_exists('ZipArchive')) {
    ok(true, 'ZipArchive available for full ZIP integration regression on this host');
} else {
    skip_case('ZipArchive integration', 'ZipArchive is not installed in this CLI environment');
}

echo "\nSummary: {$passed} passed, {$failed} failed, {$skipped} skipped.\n";
exit($failed > 0 ? 1 : 0);
