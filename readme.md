# WHMCS Discord Client Verification

This integration allows WHMCS clients to automatically receive Discord roles based on their account status. Clients with active products receive one role, while verified clients without active products receive a different role. The system automatically updates roles when client status changes.

## Features

- OAuth2 integration for secure Discord verification
- Automatic role assignment based on client status
- Different roles for active and inactive product states
- Automatic role updates via WHMCS hooks:
  - When products become active/inactive
  - When client status changes
  - Daily synchronization via cron
- CSRF protection and secure error handling

## Installation

1. **File Setup**:
   - Place `discord.php` in your WHMCS root directory
   - Add `discord.tpl` to your active template directory (e.g., `/templates/six/`)
   - Add `hooks/discord.php` to your WHMCS hooks directory

2. **Configuration**:
   Create `config.php` outside your web root with:
   ```php
   <?php
   return [
       'client_id' => '',        // Discord Application Client ID
       'secret_id' => '',        // Discord Application Secret
       'scopes' => 'identify email',
       'redirect_uri' => 'https://billing.yourdomain.com/discord.php',
       'guild_id' => '',         // Your Discord Server ID
       'active_role_id' => '',   // Role ID for clients with active products
       'default_role_id' => '',  // Role ID for verified clients without active products
       'bot_token' => ''         // Your Discord Bot Token
   ];
   ```

3. **Update File Paths**:
   In both `discord.php` and `hooks/discord.php`, update the config path:
   ```php
   $config = require '/path/to/your/config.php';
   ```

4. **Discord Setup**:
   - Create a Discord application at [Discord Developer Portal](https://discord.com/developers/applications)
   - Add your redirect URL to the OAuth2 settings
   - Create a bot and add it to your server with role management permissions
   - Create two roles:
     - One for clients with active products
     - One for verified clients without active products

5. **WHMCS Setup**:
   Create a custom client field:
   - Go to Setup > Custom Client Fields
   - Add field named "discord"
   - Set Admin Only = Yes
   - Save Changes

## Environment Variables (Optional)

For additional security, you can use environment variables instead of config values:
- `DISCORD_CLIENT_ID`
- `DISCORD_SECRET_ID`
- `DISCORD_BOT_TOKEN`

## How It Works

1. Clients visit `/discord.php` to link their Discord account
2. Upon verification:
   - Clients with active products receive the active role
   - Clients without active products receive the default role
3. Roles automatically update:
   - When products are activated/cancelled
   - When client status changes
   - During daily WHMCS cron job

## Security Notes

- Store `config.php` outside web root
- Use environment variables for sensitive data when possible
- The integration includes CSRF protection
- OAuth2 flow follows security best practices

## Support

While this project is provided as-is without official support:
- Consider starring if you find it useful
