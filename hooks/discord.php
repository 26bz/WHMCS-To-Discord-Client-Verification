<?php
// includes/hooks/discord.php

use WHMCS\Database\Capsule;
use WHMCS\View\Menu\Item as MenuItem;

// Global configuration
global $discord_config;
$discord_config = require '/config.php';
$discord_config['bot_token'] = getenv('DISCORD_BOT_TOKEN') ?: $discord_config['bot_token'];

function checkDiscordMembership($userId, $guildId, $botToken)
{
    $url = "https://discord.com/api/guilds/$guildId/members/$userId";
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bot ' . $botToken,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 10
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $httpCode === 200;
}

function updateClientDiscordId($discordId, $clientId)
{
    try {
        // Get Discord field ID
        $discordFieldId = Capsule::table('tblcustomfields')
            ->where('fieldname', 'discord')
            ->value('id');

        if (!$discordFieldId) {
            throw new Exception('Discord custom field not found');
        }
        Capsule::table('tblcustomfieldsvalues')
            ->updateOrInsert(
                [
                    'fieldid' => $discordFieldId,
                    'relid' => $clientId,
                ],
                [
                    'value' => $discordId
                ]
            );

        return true;
    } catch (Exception $e) {
        throw new Exception('Failed to update Discord ID: ' . $e->getMessage());
    }
}

add_hook('CustomFieldSave', 1, function ($vars) {
    // Get the field name for this field ID
    $fieldName = Capsule::table('tblcustomfields')
        ->where('id', $vars['fieldid'])
        ->value('fieldname');

    // Only process if it's our discord field
    if (strtolower($fieldName) === 'discord') {
        $value = $vars['value'];
        $cleanValue = preg_replace('/[^0-9]/', '', $value);

        // Validate Discord ID
        if (!empty($cleanValue)) {
            // Discord IDs are typically 17-20 digits long
            if (strlen($cleanValue) < 17 || strlen($cleanValue) > 20) {
                throw new Exception('Invalid Discord ID format');
            }

            return [
                'value' => $cleanValue
            ];
        }
    }
});
function removeRole($userId, $guildId, $roleId, $botToken)
{
    $url = "https://discord.com/api/guilds/$guildId/members/$userId/roles/$roleId";
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => "DELETE",
        CURLOPT_HTTPHEADER => [
            'Authorization: Bot ' . $botToken,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 10
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return in_array($httpCode, [204, 404]);
}

function assignRoleToUser($userId, $clientId)
{
    global $discord_config;

    // Check if client has active products
    $activeProducts = Capsule::table('tblhosting')
        ->where('userid', $clientId)
        ->where('domainstatus', 'Active')
        ->count();

    $roleId = $activeProducts > 0 ? $discord_config['active_role_id'] : $discord_config['default_role_id'];

    $url = "https://discord.com/api/guilds/{$discord_config['guild_id']}/members/$userId/roles/$roleId";
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => "PUT",
        CURLOPT_HTTPHEADER => [
            'Authorization: Bot ' . $discord_config['bot_token'],
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 10
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new Exception('Failed to assign role: ' . curl_error($ch));
    }

    if (!in_array($httpCode, [204, 404])) {
        throw new Exception("Discord API error: HTTP $httpCode - $response");
    }

    $roleToRemove = $roleId === $discord_config['active_role_id'] ?
        $discord_config['default_role_id'] :
        $discord_config['active_role_id'];
    removeRole($userId, $discord_config['guild_id'], $roleToRemove, $discord_config['bot_token']);
}
add_hook('ClientAreaSecondaryNavbar', 1, function ($secondaryNavbar) {
    try {
        if ($accountMenu = $secondaryNavbar->getChild('Account')) {
            $accountMenu->addChild(
                'customSubButton',
                [
                    'name' => 'Verify Discord',
                    'label' => 'Verify Discord',
                    'uri' => '/discord.php',
                    'order' => 84,
                ]
            );
        }
    } catch (\Exception $e) {
        logActivity("Secondary navbar hook error: " . $e->getMessage());
    }
});
add_hook('DailyCronJob', 1, function () {
    global $discord_config;

    try {
        // Get all clients with Discord IDs from custom fields
        $clients = Capsule::table('tblcustomfields')
            ->where('fieldname', 'LIKE', '%discord%')
            ->join('tblcustomfieldsvalues', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
            ->join('tblclients', 'tblcustomfieldsvalues.relid', '=', 'tblclients.id')
            ->select(
                'tblclients.id',
                'tblclients.status',
                'tblcustomfieldsvalues.value as discord_id'
            )
            ->get();

        foreach ($clients as $client) {
            if (empty($client->discord_id) || !is_numeric($client->discord_id)) {
                continue;
            }

            try {
                if ($client->status == 'Inactive') {
                    // Remove both roles if client is inactive
                    $activeRemoved = removeRole(
                        $client->discord_id,
                        $discord_config['guild_id'],
                        $discord_config['active_role_id'],
                        $discord_config['bot_token']
                    );

                    $defaultRemoved = removeRole(
                        $client->discord_id,
                        $discord_config['guild_id'],
                        $discord_config['default_role_id'],
                        $discord_config['bot_token']
                    );

                    if ($activeRemoved || $defaultRemoved) {
                        logActivity("Removed Discord roles for inactive client ID: {$client->id}");
                    }
                } else {
                    try {
                        assignRoleToUser($client->discord_id, $client->id);
                        logActivity("Updated Discord role for client ID: {$client->id}");
                    } catch (Exception $e) {
                        logActivity("Failed to update Discord role for client ID: {$client->id} - " . $e->getMessage());
                    }
                }
            } catch (Exception $e) {
                logActivity("Error processing Discord roles for client ID: {$client->id} - " . $e->getMessage());
                continue;
            }
        }
    } catch (Exception $e) {
        logActivity("Discord role sync failed: " . $e->getMessage());
    }
});

add_hook('ServiceStatusChange', 1, function ($vars) {
    global $discord_config;

    if ($vars['oldstatus'] !== 'Active' && $vars['status'] !== 'Active') {
        return;
    }

    try {
        $discordId = Capsule::table('tblcustomfields')
            ->join('tblcustomfieldsvalues', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
            ->where('tblcustomfields.fieldname', 'LIKE', '%discord%')
            ->where('tblcustomfieldsvalues.relid', $vars['userid'])
            ->value('tblcustomfieldsvalues.value');

        if ($discordId && is_numeric($discordId)) {
            assignRoleToUser($discordId, $vars['userid']);
        }
    } catch (Exception $e) {
        logActivity("Failed to update Discord role on service status change - User ID: {$vars['userid']} - " . $e->getMessage());
    }
});

add_hook('ClientStatusChange', 1, function ($vars) {
    global $discord_config;

    try {
        $discordId = Capsule::table('tblcustomfields')
            ->join('tblcustomfieldsvalues', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
            ->where('tblcustomfields.fieldname', 'LIKE', '%discord%')
            ->where('tblcustomfieldsvalues.relid', $vars['userid'])
            ->value('tblcustomfieldsvalues.value');

        if ($discordId && is_numeric($discordId)) {
            if ($vars['status'] == 'Inactive') {
                removeRole($discordId, $discord_config['guild_id'], $discord_config['active_role_id'], $discord_config['bot_token']);
                removeRole($discordId, $discord_config['guild_id'], $discord_config['default_role_id'], $discord_config['bot_token']);
            } else {
                assignRoleToUser($discordId, $vars['userid']);
            }
        }
    } catch (Exception $e) {
        logActivity("Failed to update Discord role on client status change - User ID: {$vars['userid']} - " . $e->getMessage());
    }
});
