<?php
$google_connected = SD_Social_Credentials::is_connected('google');
$meta_connected   = SD_Social_Credentials::is_connected('meta');
?>

<?php if (isset($_GET['google_connected'])) : ?>
    <div class="notice notice-success is-dismissible">
        <p><strong>✅ Google Business Profile successfully connected!</strong></p>
    </div>
<?php endif; ?>

<?php if (isset($_GET['google_error'])) : ?>
    <div class="notice notice-error is-dismissible">
        <p>Failed to save Google connection.</p>
    </div>
<?php endif; ?>

<div class="sd-social-card">
    <h2>Platform Connections</h2>
    
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 20px;">
        
        <!-- Google -->
        <div>
            <h3>Google Business Profile</h3>
            <span class="sd-social-status sd-social-status--<?php echo $google_connected ? 'connected' : 'disconnected'; ?>">
                <?php echo $google_connected ? '✅ Connected' : 'Not Connected'; ?>
            </span>
            <p><em>Main solodrive.pro business listing</em></p>
            
            <?php if ($google_connected): ?>
                <button onclick="if(confirm('Disconnect Google?')){window.location='<?php echo wp_nonce_url(admin_url('admin-post.php?action=sd_social_disconnect&platform=google'), 'sd_social_disconnect'); ?>';}" 
                        class="button button-link-delete">Disconnect</button>
            <?php else: ?>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <input type="hidden" name="action" value="sd_social_connect_google">
                    <?php wp_nonce_field('sd_social_connect_google'); ?>
                    <button type="submit" class="button button-primary">Connect Google Business Profile</button>
                </form>
            <?php endif; ?>
        </div>

        <!-- Meta -->
        <div class="sd-platform-card">
            <h3>Meta (Facebook & Instagram)</h3>
            <?php if (SD_Social_Credentials::is_connected('meta')): 
                $meta_creds = SD_Social_Credentials::get('meta');
                $page_name = $meta_creds['default_page']['name'] ?? 'Facebook Page';
            ?>
                <span class="sd-connected">✅ Connected</span>
                <p><strong>Page:</strong> <?php echo esc_html($page_name); ?></p>
                <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=sd_social_disconnect&platform=meta'), 'sd_social_disconnect'); ?>" 
                class="button button-secondary">Disconnect</a>
            <?php else: ?>
                <span class="sd-not-connected">Not Connected</span>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <?php wp_nonce_field('sd_social_connect_meta'); ?>
                    <input type="hidden" name="action" value="sd_social_connect_meta">
                    <button type="submit" class="button button-primary">Connect Meta Accounts</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="sd-social-card">
    <h2>Quick Publish</h2>
    <p>Basic publishing form will go here (Phase 1.5).</p>
</div>