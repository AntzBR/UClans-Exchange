<?php

declare(strict_types=1);

final class MigrationRules
{
    public static function prefixRules(): array
    {
        $rules = [
            ['banner.yml', 'Banner', 'modules/Banner/config.yml', 'Config.Banner'],
            ['config.yml', 'Clan.home', 'modules/Home/config.yml', 'Config.Home'],
            ['config.yml', 'Clan.chat', 'modules/Chat/config.yml', 'Clan.chat'],
            ['config.yml', 'Clan.rival_remove', 'modules/Rivalally/config.yml', 'Config.Rival.remove'],
            ['config.yml', 'Clan.ally_invite', 'modules/Rivalally/config.yml', 'Config.Ally.invite'],
            ['config.yml', 'Clan.logger', 'modules/Logger/config.yml', 'Config.Logger'],
            ['config.yml', 'Clan.mail', 'modules/Mail/config.yml', 'Config.Mail'],
            ['config.yml', 'Config.special_permissions.bypass_home_regions', 'modules/Home/config.yml', 'Config.special_permissions.bypass_home_regions'],
            ['config.yml', 'Config.special_permissions.bypass_home_worlds', 'modules/Home/config.yml', 'Config.special_permissions.bypass_home_worlds'],

            ['level.yml', 'Level.points', 'modules/Levelup/config.yml', 'Level.points'],
            ['level.yml', 'Level.patent', 'modules/Levelup/config.yml', 'Level.patent'],
            ['level.yml', 'Level.actions', 'modules/Levelup/config.yml', 'Level.actions'],
            ['level.yml', 'Level.unlocker.slots', 'config.yml', 'Clan.unlocker.slots'],
            ['level.yml', 'Level.unlocker.home', 'modules/Home/config.yml', 'Config.Unlocker.home'],
            ['level.yml', 'Level.unlocker.ally', 'modules/Rivalally/config.yml', 'Config.unlocker.ally'],
            ['level.yml', 'Level.unlocker.rival', 'modules/Rivalally/config.yml', 'Config.unlocker.rival'],
            ['level.yml', 'Level.unlocker.banner', 'modules/Banner/config.yml', 'Config.Unlocker.banner'],

            ['modules.yml', 'Modules.holograms.preference', 'modules.yml', 'Config.modules.preferences.holograms'],
            ['modules.yml', 'Modules.regions.preference', 'modules.yml', 'Config.modules.preferences.regions'],
            ['modules.yml', 'Modules.money.preference', 'modules.yml', 'Config.modules.preferences.money'],
            ['modules.yml', 'Modules.map.preference', 'modules.yml', 'Config.modules.preferences.map'],
            ['modules.yml', 'Modules.scoreboard.preference', 'modules.yml', 'Config.modules.preferences.scoreboard'],
            ['modules.yml', 'Modules.internal', 'modules.yml', 'Config.hooks'],

            ['chat.yml', 'Chat.clanhomecreate', 'modules/Home/chat.yml', 'Chat.clanhomecreate'],
        ];

        foreach (['clan_homes','clan_allyhome','clan_delhome_confirm','listhomes'] as $menu)
            $rules[] = ['gui.yml', "General.{$menu}", 'modules/Home/gui.yml', "General.{$menu}"];
        foreach (['banner_home','banner_home_pre','banner_delete','banner_upload'] as $menu)
            $rules[] = ['gui.yml', "General.{$menu}", 'modules/Banner/gui.yml', "General.{$menu}"];
        foreach (['clan_logger','clear_logger_confirm'] as $menu)
            $rules[] = ['gui.yml', "General.{$menu}", 'modules/Logger/gui.yml', "General.{$menu}"];
        foreach (['clan_mail','clear_mail_confirm'] as $menu)
            $rules[] = ['gui.yml', "General.{$menu}", 'modules/Mail/gui.yml', "General.{$menu}"];
        foreach (['rival','ally','clan_rival_search','clan_ally_search'] as $menu)
            $rules[] = ['gui.yml', "General.{$menu}", 'modules/Rivalally/gui.yml', "General.{$menu}"];

        foreach (['levelup_home','levelup_confirm'] as $menu)
            $rules[] = ['gui2.yml', "General.{$menu}", 'modules/Levelup/gui.yml', "General.{$menu}"];
        $rules[] = ['gui2.yml', 'General.levelup_rewards', 'modules/Rewards/gui.yml', 'General.levelup_rewards'];
        $rules[] = ['gui2.yml', 'General.anvil_clanhomecreate', 'modules/Home/gui.yml', 'General.anvil_home_create'];

        foreach ([
            'anvil_clancreate','anvil_changenickname','anvil_clandiscordcreatewebhook','anvil_clanmodtag','anvil_clandesc',
            'admin_home','admin_languages','admin_clans_home','admin_clan_edit','admin_players_home','admin_player_edit',
            'admin_extension','admin_extension_list','admin_addons_home','disband_confirm','changeleader_confirm',
            'player_top','player_languages','player_permissions'
        ] as $menu) $rules[] = ['gui2.yml', "General.{$menu}", 'gui.yml', "General.{$menu}"];

        return $rules;
    }

    public static function exactRules(): array
    {
        return [
            'banner.yml|Banner.enabled' => [['modules.yml', 'Config.modules.list.banner.enabled']],
            'config.yml|Clan.logger.enabled' => [['modules.yml', 'Config.modules.list.logger.enabled']],
            'config.yml|Config.extensions.autodownload_enabled' => [['modules.yml', 'Config.extensions.auto_download']],
            'level.yml|Level.default.homes' => [['modules/Home/config.yml', 'Config.Home.default_amount']],
            'level.yml|Level.default.ally' => [['modules/Rivalally/config.yml', 'Config.Ally.default_limit']],
            'level.yml|Level.default.rival' => [['modules/Rivalally/config.yml', 'Config.Rival.default_limit']],
            'level.yml|Level.default.slots' => [['config.yml', 'Clan.slots.default']],
            'storage.yml|Storage.databases.logger_table' => [['modules/Logger/config.yml', 'Storage.databases.logger_table']],
            'storage.yml|Storage.databases.mail_table' => [['modules/Mail/config.yml', 'Storage.databases.mail_table']],
            'storage.yml|Storage.databases.rewards_table' => [['modules/Rewards/config.yml', 'Storage.databases.rewards_table']],
            'config.yml|Config.timezone' => [['config.yml', 'Config.timezone'], ['modules/Logger/config.yml', 'Config.timezone']],
            'config.yml|Config.date_format_short' => [
                ['config.yml', 'Config.date_format_short'],
                ['modules/Banner/config.yml', 'Config.date_format_short'],
                ['modules/Home/config.yml', 'Config.date_format_short'],
                ['modules/Levelup/config.yml', 'Config.date_format_short'],
                ['modules/Logger/config.yml', 'Config.date_format_short'],
                ['modules/Mail/config.yml', 'Config.date_format_short'],
                ['modules/Rivalally/config.yml', 'Config.date_format_short'],
                ['modules/Discord/config.yml', 'Config.date_format_short'],
            ],

            // ClanGladiator V8 -> redesigned V9.1 settings with direct semantic equivalents.
            'addons/ClanGladiator/config.yml|Config.Gladiator.scheduler.enabled' => [['addons/ClanGladiator/config.yml', 'Scheduler.enabled']],
            'addons/ClanGladiator/config.yml|Config.Gladiator.scheduler.timezone' => [['addons/ClanGladiator/config.yml', 'General.timezone']],
            'addons/ClanGladiator/config.yml|Config.Gladiator.clear_effects.enabled' => [['addons/ClanGladiator/config.yml', 'Server-Kit.clear-potion-effects-on-entry']],
            'addons/ClanGladiator/config.yml|Config.borders.enable' => [['addons/ClanGladiator/config.yml', 'WorldBorder.enabled']],
            'addons/ClanGladiator/config.yml|Config.borders.min_size' => [['addons/ClanGladiator/config.yml', 'Default-Arena.final-border-size']],

            'moderation.yml|Moderation.permissions.HOMEMANAGER.name' => [['modules/Home/config.yml', 'Config.Permissions.manager.name']],
            'moderation.yml|Moderation.permissions.ALLYMANAGER.name' => [['modules/Rivalally/config.yml', 'Config.Permissions.ally-manager.name']],
            'moderation.yml|Moderation.permissions.RIVALMANAGER.name' => [['modules/Rivalally/config.yml', 'Config.Permissions.rival-manager.name']],
            'moderation.yml|Moderation.permissions.BANNERMANAGER.name' => [['modules/Banner/config.yml', 'Config.Permissions.manager.name']],
            'moderation.yml|Moderation.permissions.VIEW_LOGGER.name' => [['modules/Logger/config.yml', 'Config.Permissions.view.name']],
            'moderation.yml|Moderation.permissions.EDIT_LOGGER.name' => [['modules/Logger/config.yml', 'Config.Permissions.edit.name']],
            'moderation.yml|Moderation.permissions.EDIT_MAIL.name' => [['modules/Mail/config.yml', 'Config.Permissions.edit.name']],
            'moderation.yml|Moderation.permissions.SEND_MAIL.name' => [['modules/Mail/config.yml', 'Config.Permissions.send.name']],
            'moderation.yml|Moderation.permissions.READ_MAIL.name' => [['modules/Mail/config.yml', 'Config.Permissions.read.name']],
            'moderation.yml|Moderation.permissions.LEVEL_VIEW.name' => [['modules/Levelup/config.yml', 'Config.Permissions.view.name']],
            'moderation.yml|Moderation.permissions.LEVEL_UP.name' => [['modules/Levelup/config.yml', 'Config.Permissions.up.name']],
            'moderation.yml|Moderation.permissions.LEVEL_REWARDS.name' => [['modules/Rewards/config.yml', 'Config.Permissions.use.name']],
        ];
    }

    /**
     * Subtrees that are intentionally replaced as a whole because users may add/remove arbitrary child keys.
     */
    public static function dynamicSubtreeRules(): array
    {
        return [
            ['banner.yml', 'Banner.groups', 'modules/Banner/config.yml', 'Config.Banner.groups'],
            ['level.yml', 'Level.patent.list', 'modules/Levelup/config.yml', 'Level.patent.list'],
            ['gui.yml', 'Insertion', 'gui.yml', 'Insertion'],
        ];
    }

    public static function commandRules(): array
    {
        return [
            'home' => ['modules/Home/commands.yml', 'Commands.player'],
            'chat' => ['modules/Chat/commands.yml', 'Commands.player'],
            'banner' => ['modules/Banner/commands.yml', 'Commands.player'],
            'logger' => ['modules/Logger/commands.yml', 'Commands.player'],
            'discord' => ['modules/Discord/commands.yml', 'Commands.player'],
            'mail' => ['modules/Mail/commands.yml', 'Commands.player'],
            'level' => ['modules/Levelup/commands.yml', 'Commands.player.level'],
            'rewards' => ['modules/Rewards/commands.yml', 'Commands.player.rewards'],
            'rival' => ['modules/Rivalally/commands.yml', 'Commands.player.rival'],
            'ally' => ['modules/Rivalally/commands.yml', 'Commands.player.ally'],
        ];
    }

    public static function ignoredPrefixes(): array
    {
        return [
            'config.yml|Config.language_repo_V7',
            'config.yml|Config.language_repo_V8',
            'config.yml|Config.extensions.repo_V7',
            'config.yml|Config.extensions.repo_V8',
            'config.yml|Config.extensions.list',
            'config.yml|Config.extensions.version_file',
            'config.yml|Config.beta',
            'config.yml|Clan.logger.discord',
            'level.yml|Level.action_requirements',
            'level.yml|Level.requeriments',
            'level.yml|Level.unlocker.desc',
            'level.yml|Level.unlocker.modtag',
            'level.yml|Level.unlocker.invite_members',
            'level.yml|Level.unlocker.remove_members',
            'level.yml|Level.unlocker.invite_war',
            'level.yml|Level.unlocker.accept_war',
            'level.yml|Level.unlocker.regroup',
            'level.yml|Level.unlocker.promote',
            'level.yml|Level.unlocker.demote',
            'level.yml|Level.unlocker.banner_upload',
            'chat.yml|Chat.clandiscordcreatewebhook',
            'gui.yml|General.clan_settings.items.webhook',
            'gui.yml|General.clan_top.items.war.enabled',

            // V9 removed these legacy per-command presentation blocks. Keep the
            // still-supported disband.sound.on_error path out of the broad rule.
            'commands.yml|Commands.list.clan.create.title',
            'commands.yml|Commands.list.clan.create.actionbar',
            'commands.yml|Commands.list.clan.create.sound',
            'commands.yml|Commands.list.clan.cancel.title',
            'commands.yml|Commands.list.clan.cancel.actionbar',
            'commands.yml|Commands.list.clan.cancel.sound',
            'commands.yml|Commands.list.clan.desc.title',
            'commands.yml|Commands.list.clan.desc.actionbar',
            'commands.yml|Commands.list.clan.desc.sound',
            'commands.yml|Commands.list.clan.ff.title',
            'commands.yml|Commands.list.clan.ff.actionbar',
            'commands.yml|Commands.list.clan.ff.sound',
            'commands.yml|Commands.list.clan.disband.title',
            'commands.yml|Commands.list.clan.disband.actionbar',
            'commands.yml|Commands.list.clan.disband.sound.on_help',
            'commands.yml|Commands.list.clan.disband.sound.on_success',

            // Malformed legacy key (trailing space) has no V9 equivalent.
            "storage.yml|Storage.'datasource '",

            'addons/ClanBank/config.yml|Config.version',
            'addons/ClanChest/config.yml|Config.version',
            'addons/ClanChest/config.yml|Config.language',
            'addons/ClanFarmCoins/config.yml|Config.version',
            'addons/ClanFarmCoins/config.yml|Config.language',
            'addons/ClanLand/config.yml|Config.version',
            'addons/ClanLand/config.yml|Config.language',
            'addons/ClanLand/config.yml|Config.Modules.placeholderapi',
            'addons/ClanLeaderboard/config.yml|Config.version',
            'addons/ClanLeaderboard/config.yml|Config.language',
            'addons/ClanLeague/config.yml|Config.version',
            'addons/ClanLeague/config.yml|Config.language',
            'addons/ClanNexus/config.yml|Config.version',
            'addons/ClanNexus/config.yml|Config.language',
            'addons/ClanQuest/config.yml|Config.version',
            'addons/ClanQuest/config.yml|Config.language',
            'addons/ClanWar/config.yml|Config.version',
            'addons/ClanWar/config.yml|Config.language',
            'addons/ClanGladiator/config.yml|Config.version',
            'addons/ClanGladiator/config.yml|Config.language',
        ];
    }

    public static function manualRules(): array
    {
        $rules = [
            'commands.yml|Commands.list.clan.home.subcommands' => 'V9 no longer stores the Home subcommand list in the same form; review any V8 customization manually.',
            'commands.yml|Commands.list.clan.banner.subcommands' => 'V9 defines Banner subcommands directly in the module command file; review any customized V8 list manually.',
            'commands.yml|Commands.list.clan.logger.subcommands' => 'V9 defines Logger subcommands directly in the module command file; review any customized V8 list manually.',
            'commands.yml|Commands.list.clan.discord.subcommands' => 'The V9 Discord module changed its command structure; review customized V8 subcommands manually.',
            'commands.yml|Commands.list.clan.mail.subcommands' => 'V9 defines Mail subcommands directly in the module command file; review any customized V8 list manually.',
            'commands.yml|Commands.list.clan.level.subcommands' => 'V9 Levelup command structure changed; review any customized V8 list manually.',
            'commands.yml|Commands.list.clan.rewards.subcommands' => 'V9 Rewards command structure changed; review any customized V8 list manually.',
            'level.yml|Level.rewards' => 'This older V8 rewards block has no direct V9 path. V9 separates level progression and claimable rewards into Levelup and Rewards modules; review this legacy block manually.',
            'gui2.yml|General.levelup_home.items.rewards' => 'V9 removed the duplicate Rewards shortcut from the Levelup menu. Rewards now has its own module icon; review this older Levelup-menu icon if it was intentionally customized.',

            'addons/ClanChest/config.yml|Config.Chest.enabled' => 'ClanChest no longer has this V9 config toggle. If it was disabled in V8, disable/remove the addon in V9.',
            'addons/ClanFarmCoins/config.yml|Config.Farm.enabled' => 'ClanFarmCoins no longer has this V9 config toggle. If it was disabled in V8, disable/remove the addon in V9.',
            'addons/ClanLand/config.yml|Config.Land.enabled' => 'ClanLand no longer has this V9 config toggle. If it was disabled in V8, disable/remove the addon in V9.',
            'addons/ClanLeaderboard/config.yml|Config.Leaderboard.enabled' => 'ClanLeaderboard no longer has this V9 config toggle. If it was disabled in V8, disable/remove the addon in V9.',
            'addons/ClanLeague/config.yml|Config.League.enabled' => 'ClanLeague no longer has this V9 config toggle. If it was disabled in V8, disable/remove the addon in V9.',
            'addons/ClanNexus/config.yml|Config.Nexus.enabled' => 'ClanNexus no longer has this V9 config toggle. If it was disabled in V8, disable/remove the addon in V9.',
            'addons/ClanQuest/config.yml|Config.Quest.enabled' => 'ClanQuest no longer has this V9 config toggle. If it was disabled in V8, disable/remove the addon in V9.',
            'addons/ClanWar/config.yml|Config.War.enabled' => 'ClanWar no longer has this V9 config toggle. If it was disabled in V8, disable/remove the addon in V9.',
            'addons/ClanChest/gui.yml|Chest.buy_chest.items.fill.slot' => 'This GUI slot was removed in the V9 ClanChest layout; review the buy_chest fill item if it was customized.',
            'gui.yml|General.clan_stats.items.homes' => 'The old Homes item was removed from the V9 clan_stats menu; review this customization manually.',
        ];

        foreach (['home','banner','logger','mail','levelup','rewards','rival','ally'] as $icon)
            $rules["gui.yml|General.clan_home.items.{$icon}.amount"] = 'V9 module icons no longer use the old GUI amount field.';

        return $rules;
    }

    public static function rolePermissionRules(): array
    {
        return [
            'HOMEMANAGER' => ['modules/Home/config.yml', 'Config.Permissions.manager.applicable_roles'],
            'ALLYMANAGER' => ['modules/Rivalally/config.yml', 'Config.Permissions.ally-manager.applicable_roles'],
            'RIVALMANAGER' => ['modules/Rivalally/config.yml', 'Config.Permissions.rival-manager.applicable_roles'],
            'BANNERMANAGER' => ['modules/Banner/config.yml', 'Config.Permissions.manager.applicable_roles'],
            'VIEW_LOGGER' => ['modules/Logger/config.yml', 'Config.Permissions.view.applicable_roles'],
            'EDIT_LOGGER' => ['modules/Logger/config.yml', 'Config.Permissions.edit.applicable_roles'],
            'EDIT_MAIL' => ['modules/Mail/config.yml', 'Config.Permissions.edit.applicable_roles'],
            'SEND_MAIL' => ['modules/Mail/config.yml', 'Config.Permissions.send.applicable_roles'],
            'READ_MAIL' => ['modules/Mail/config.yml', 'Config.Permissions.read.applicable_roles'],
            'LEVEL_VIEW' => ['modules/Levelup/config.yml', 'Config.Permissions.view.applicable_roles'],
            'LEVEL_UP' => ['modules/Levelup/config.yml', 'Config.Permissions.up.applicable_roles'],
            'LEVEL_REWARDS' => ['modules/Rewards/config.yml', 'Config.Permissions.use.applicable_roles'],
            'EDIT_WEBHOOK' => ['modules/Discord/config.yml', 'Config.Permissions.edit.applicable_roles'],
        ];
    }

    /**
     * Older V8 releases may contain valid keys that are absent from the 8.21
     * reference template. These are known-safe structural insertions.
     */
    public static function legacyInsertRules(): array
    {
        return [
            'moderation.yml|Moderation.list.leader.upgradable' => ['moderation.yml', 'Moderation.list.leader'],
        ];
    }

    public static function storageFanout(): array
    {
        return [
            'Storage.type', 'Storage.mysql.host', 'Storage.mysql.port', 'Storage.mysql.base', 'Storage.mysql.useSSL',
            'Storage.user', 'Storage.pass', 'Storage.datasource.mysql', 'Storage.datasource.mysql_class',
            'Storage.datasource.sqlite', 'Storage.datasource.sqlite_class'
        ];
    }
}
