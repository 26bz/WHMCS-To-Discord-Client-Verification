<?php

use WHMCS\Authentication\CurrentUser;
use WHMCS\ClientArea;
use WHMCS\Database\Capsule;

define('CLIENTAREA', true);

require __DIR__ . '/init.php';

// Load config and hooks
$config = require '/config.php';
require_once __DIR__ . '/includes/hooks/discord.php';

// Use environment variables for sensitive data (loaded from .env or config.php)
$client_id = getenv('DISCORD_CLIENT_ID') ?: $config['client_id'];
$secret_id = getenv('DISCORD_SECRET_ID') ?: $config['secret_id'];
$scopes = $config['scopes'];
$redirect_uri = $config['redirect_uri'];

session_start();
$ca = new ClientArea();
$ca->setPageTitle('Discord Connection');
$ca->initPage();
$ca->assign('verified', false);

$currentUser = new CurrentUser();
if (!$currentUser->isAuthenticatedUser()) {
    $ca->assign('message', "You must be logged in to link your Discord account.");
    $ca->setTemplate('discord');
    $ca->output();
    exit;
}

$client = $currentUser->client();
if (!$client) {
    $ca->assign('message', "Unable to retrieve client information.");
    $ca->setTemplate('discord');
    $ca->output();
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'verify') {
    // Generate CSRF token
    $csrfToken = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $csrfToken;

    $authorizationUrl = 'https://discord.com/oauth2/authorize?response_type=code&client_id=' . $client_id .
        '&redirect_uri=' . urlencode($redirect_uri) .
        '&scope=' . urlencode($scopes) .
        '&state=' . $csrfToken;

    header('Location: ' . $authorizationUrl);
    exit();
}

// Process OAuth flow
if (isset($_GET['code'])) {
    // Verify CSRF token
    if (!isset($_GET['state']) || !isset($_SESSION['csrf_token']) || $_GET['state'] !== $_SESSION['csrf_token']) {
        $ca->assign('message', "Invalid security token. Please try again.");
    } else {
        try {
            // Exchange authorization code for access token
            $tokenData = exchangeAuthorizationCodeForAccessToken($_GET['code'], $client_id, $secret_id, $redirect_uri);
            $userInfo = getUserInfo($tokenData->access_token);

            if (!isset($userInfo->id)) {
                throw new Exception("Failed to retrieve user information from Discord.");
            }

            // Update Discord ID and assign role
            updateClientDiscordId($userInfo->id, $client->id);

            try {
                assignRoleToUser($userInfo->id, $client->id);
                logActivity("Discord successfully linked for Client ID: " . $client->id);

                // Get user profile data
                $avatarUrl = isset($userInfo->avatar) ?
                    "https://cdn.discordapp.com/avatars/{$userInfo->id}/{$userInfo->avatar}.png" :
                    "https://cdn.discordapp.com/embed/avatars/0.png";

                $username = htmlspecialchars($userInfo->username);
                $discriminator = isset($userInfo->discriminator) && $userInfo->discriminator !== '0' ?
                    "#{$userInfo->discriminator}" : '';

                // Assign template variables individually
                $ca->assign('verified', true);
                $ca->assign('avatar', $avatarUrl);
                $ca->assign('username', $username);
                $ca->assign('discriminator', $discriminator);
                $ca->assign('message', "Successfully linked your Discord account!");
            } catch (Exception $e) {
                logActivity("Failed to assign Discord role for Client ID: " . $client->id . " - " . $e->getMessage());
                $ca->assign('verified', false);
                $ca->assign('message', "Discord Linked Successfully, but failed to assign role: " . htmlspecialchars($e->getMessage()));
            }
        } catch (Exception $e) {
            logActivity("Discord linking error for Client ID: " . $client->id . " - " . $e->getMessage());
            $ca->assign('verified', false);
            $ca->assign('message', "An error occurred: " . htmlspecialchars($e->getMessage()));
        }
    }
} else {
    // Check for existing Discord link
    $existingDiscord = getExistingDiscordInfo($client->id);

    if ($existingDiscord) {
        $ca->assign('verified', true);
        $ca->assign('avatar', $existingDiscord['avatar']);
        $ca->assign('username', $existingDiscord['username']);
        $ca->assign('discriminator', $existingDiscord['discriminator']);
        $ca->assign('message', 'Your Discord account is linked');
    } else {
        $ca->assign('verified', false);
        $ca->assign('message', 'Link your Discord account to your client area');
    }
}
function exchangeAuthorizationCodeForAccessToken($code, $client_id, $secret_id, $redirect_uri)
{
    $ch = curl_init('https://discord.com/api/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => $client_id,
            'client_secret' => $secret_id,
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirect_uri
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception('Failed to retrieve access token: ' . curl_error($ch));
    }

    $data = json_decode($response);
    if (!isset($data->access_token)) {
        throw new Exception('Invalid token response: ' . $response);
    }

    curl_close($ch);
    return $data;
}

function getUserInfo($accessToken)
{
    $ch = curl_init('https://discord.com/api/users/@me');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception('Failed to retrieve user info: ' . curl_error($ch));
    }

    $data = json_decode($response);
    if (!isset($data->id)) {
        throw new Exception('Invalid user info response: ' . $response);
    }

    curl_close($ch);
    return $data;
}
function getDiscordUserInfo($userId, $botToken)
{
    $url = "https://discord.com/api/users/$userId";
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

    if ($response === false) {
        throw new Exception('Failed to retrieve Discord user info: ' . curl_error($ch));
    }

    if ($httpCode !== 200) {
        throw new Exception('Discord API error: ' . $response);
    }

    return json_decode($response, true);
}
function getExistingDiscordInfo($clientId)
{
    global $discord_config;
    try {
        $discordId = Capsule::table('tblcustomfields')
            ->join('tblcustomfieldsvalues', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
            ->where('tblcustomfields.fieldname', 'LIKE', '%discord%')
            ->where('tblcustomfieldsvalues.relid', $clientId)
            ->value('tblcustomfieldsvalues.value');

        if ($discordId && is_numeric($discordId)) {
            $userInfo = getDiscordUserInfo($discordId, $discord_config['bot_token']);
            if ($userInfo) {
                return [
                    'id' => $discordId,
                    'username' => $userInfo['username'],
                    'discriminator' => isset($userInfo['discriminator']) && $userInfo['discriminator'] !== '0' ?
                        "#{$userInfo['discriminator']}" : '',
                    'avatar' => isset($userInfo['avatar']) ?
                        "https://cdn.discordapp.com/avatars/{$discordId}/{$userInfo['avatar']}.png" :
                        "https://cdn.discordapp.com/embed/avatars/0.png"
                ];
            }
        }
    } catch (Exception $e) {
        logActivity("Failed to fetch existing Discord info - Client ID: {$clientId} - " . $e->getMessage());
    }
    return null;
}
$ca->setTemplate('discord');
$ca->output();
