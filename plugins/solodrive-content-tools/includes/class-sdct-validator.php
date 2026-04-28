<?php
if (!defined('ABSPATH')) {
    exit;
}

class SDCT_Validator {
    public static function validate_page($page) {
        $errors = array();
        $warnings = array();

        $meta = isset($page['meta']) ? $page['meta'] : array();
        $body = isset($page['body']) ? $page['body'] : '';

        foreach (array('title', 'slug', 'meta_title', 'meta_description', 'summary', 'cta') as $field) {
            if (empty($meta[$field])) {
                $errors[] = 'Missing front matter: ' . $field;
            }
        }

        /*
         * Do not require a body H1.
         *
         * WordPress/Astra already renders the page title from front matter title/post_title.
         * Requiring "# Page Title" inside the markdown body causes duplicate visible titles.
         */

        if (!preg_match('/^##\s+.+/m', $body)) {
            $warnings[] = 'No H2 sections found.';
        }

        if (stripos($body, 'request access') === false && empty($meta['cta'])) {
            $warnings[] = 'No obvious conversion CTA.';
        }

        if (isset($meta['meta_description']) && strlen($meta['meta_description']) > 165) {
            $warnings[] = 'Meta description is longer than 165 characters.';
        }

        return array(
            'errors' => $errors,
            'warnings' => $warnings,
        );
    }
}
