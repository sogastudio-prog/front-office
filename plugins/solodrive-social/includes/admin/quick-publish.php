<?php
// Quick Publish Form for Internal Social Module
if (!defined('ABSPATH')) { exit; }
?>

<div class="sd-social-card">
    <h2>Quick Publish</h2>
    <p>Post to Google Business Profile and/or Meta</p>

    <?php if (isset($_GET['publish_success'])) : ?>
        <div class="notice notice-success"><p>✅ Post published successfully!</p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
        <input type="hidden" name="action" value="sd_social_quick_publish">
        <?php wp_nonce_field('sd_social_quick_publish'); ?>

        <textarea name="message" rows="5" required style="width:100%;"></textarea>
        <input type="url" name="link" placeholder="https://solodrive.pro/booking/..." style="width:100%; margin:10px 0;" />

        <button type="submit" name="target" value="google" class="button button-primary">Publish to Google</button>
        <button type="submit" name="target" value="meta" class="button button-primary">Publish to Meta</button>
        <button type="submit" name="target" value="both" class="button button-primary">Publish to Both</button>
    </form>
</div>

<style>
.sd-quick-publish-form textarea { width: 100%; max-width: 100%; }
.sd-form-field { margin-bottom: 16px; }
.sd-form-field label { display: block; margin-bottom: 6px; font-weight: 600; }
</style>