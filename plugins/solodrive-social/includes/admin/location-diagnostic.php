<?php
if (!defined('ABSPATH')) { exit; }
?>

<div class="sd-social-card">
    <h2>Google Business Profile Diagnostic</h2>
    <p>This will help us find your Account ID and Location ID.</p>

    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
        <input type="hidden" name="action" value="sd_social_diagnose_locations">
        <?php wp_nonce_field('sd_social_diagnose_locations'); ?>
        <button type="submit" class="button button-primary">List My Google Business Accounts & Locations</button>
    </form>
</div>
