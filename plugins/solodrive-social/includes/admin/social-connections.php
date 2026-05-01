<?php
// Called via do_action('sd_social_admin_tabs')

$google_connected = SD_Social_Credentials::is_connected('google');
$meta_connected   = SD_Social_Credentials::is_connected('meta');
?>

<div class="sd-social-card">
    <h2>Platform Connections</h2>
    
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 20px;">
        
        <!-- Google Business Profile -->
        <div>
            <h3>Google Business Profile</h3>
            <span class="sd-social-status sd-social-status--<?php echo $google_connected ? 'connected' : 'disconnected'; ?>">
                <?php echo $google_connected ? 'Connected' : 'Not Connected'; ?>
            </span>
            <p><em>Main solodrive.pro business listing</em></p>
            
            <?php if (!$google_connected): ?>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <input type="hidden" name="action" value="sd_social_connect_google">
                    <?php wp_nonce_field('sd_social_connect_google'); ?>
                    <button type="submit" class="button button-primary">Connect Google Business Profile</button>
                </form>
            <?php else: ?>
                <button onclick="if(confirm('Disconnect Google?')){window.location='<?php echo wp_nonce_url(admin_url('admin-post.php?action=sd_social_disconnect&platform=google'), 'sd_social_disconnect'); ?>';}" 
                        class="button button-link-delete">Disconnect</button>
            <?php endif; ?>
        </div>

        <!-- Meta (Facebook + Instagram) -->
        <div>
            <h3>Meta (Facebook & Instagram)</h3>
            <span class="sd-social-status sd-social-status--<?php echo $meta_connected ? 'connected' : 'disconnected'; ?>">
                <?php echo $meta_connected ? 'Connected' : 'Not Connected'; ?>
            </span>
            
            <?php if (!$meta_connected): ?>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <input type="hidden" name="action" value="sd_social_connect_meta">
                    <?php wp_nonce_field('sd_social_connect_meta'); ?>
                    <button type="submit" class="button button-primary">Connect Meta Accounts</button>
                </form>
            <?php else: ?>
                <button onclick="if(confirm('Disconnect Meta?')){window.location='<?php echo wp_nonce_url(admin_url('admin-post.php?action=sd_social_disconnect&platform=meta'), 'sd_social_disconnect'); ?>';}" 
                        class="button button-link-delete">Disconnect</button>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="sd-social-card">
    <h2>Quick Publish</h2>
    <p>Basic publishing form will go here (Phase 1.5).</p>
    <!-- SD_Social_Publisher::render_quick_publish_form(); -->
</div>