<?php
// Quick Publish Form for Internal Social Module
if (!defined('ABSPATH')) { exit; }
?>

<div class="sd-social-card">
    <h2>Quick Publish</h2>
    <p>Post directly to Google Business Profile (Local Post)</p>

    <?php if (isset($_GET['publish_success'])) : ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>✅ Post published successfully to Google Business Profile!</strong></p>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['publish_error'])) : ?>
        <div class="notice notice-error is-dismissible">
            <p>Failed to publish: <?php echo esc_html($_GET['publish_error']); ?></p>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" class="sd-quick-publish-form">
        <input type="hidden" name="action" value="sd_social_quick_publish">

        <?php wp_nonce_field('sd_social_quick_publish'); ?>

        <div class="sd-form-field">
            <label for="message">Message / Offer <span class="required">*</span></label>
            <textarea name="message" id="message" rows="5" placeholder="Example: 🚗 Need a ride from Valdosta to Atlanta Airport this Friday? Book direct and save! #SoloDrive" required></textarea>
        </div>

        <div class="sd-form-field">
            <label for="link">Link (optional)</label>
            <input type="url" name="link" id="link" placeholder="https://solodrive.pro/booking/..." />
        </div>

        <button type="submit" class="button button-primary">Publish to Google Business Profile</button>
    </form>
</div>

<style>
.sd-quick-publish-form textarea { width: 100%; max-width: 100%; }
.sd-form-field { margin-bottom: 16px; }
.sd-form-field label { display: block; margin-bottom: 6px; font-weight: 600; }
</style>