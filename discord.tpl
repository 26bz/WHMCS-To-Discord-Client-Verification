<div class="w-100 text-center mb-10">
    {if $verified}
        <div class="d-flex justify-content-center align-items-center mb-4">
            <div class="text-center">
                <img src="{$avatar}" alt="Discord Avatar" style="width: 96px; height: 96px; border-radius: 50%; margin-bottom: 15px;">
                <h1 class="mb-2">{$message}</h1>
                <p class="mb-4">Connected as: <strong>{$username}{$discriminator}</strong></p>
                <div class="btn-group">
                    <a class="btn btn-primary mt-2" href="{$smarty.const.SITE_URL}/discord.php?action=verify">
                        <i class="fas fa-sync"></i> Re-sync Account
                    </a>
                    <a class="btn btn-secondary mt-2 ms-2" href="/">Return to home</a>
                </div>
            </div>
        </div>
    {else}
        <h1 class="mb-5">{$message}</h1>
        <a class="btn btn-primary mt-2" href="{$smarty.const.SITE_URL}/discord.php?action=verify">
            <i class="fas fa-check"></i> Verify Discord
        </a>
        <a class="btn btn-secondary mt-2 ms-2" href="/">Return to home</a>
    {/if}
</div>
