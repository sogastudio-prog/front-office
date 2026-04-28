<?php
if (!defined('ABSPATH')) {
    exit;
}

class SDCT_Validator {
    public static function validate_page($page) {
        $errors = array();
        $warnings = array();
        $meta = $page['meta'];
        $body = $page['body'];

        foreach (array('title', 'slug', 'meta_title', 'meta_description', 'summary', 'cta') as $field) {
            if (empty($meta[$field])) {
                $errors[] = 'Missing front matter: ' . $field;
            }
        }

        if (!preg_match('/^#\s+.+/m', $body)) {
            $errors[] = 'Missing H1 heading in body.';
        }

        if (!preg_match('/^##\s+.+/m', $body)) {
            $warnings[] = 'No H2 sections found.';
        }

        if (stripos($body, 'request access') === false && empty($meta['cta'])) {
            $warnings[] = 'No obvious conversion CTA.';
        }

        if (isset($meta['meta_description']) && strlen($meta['meta_description']) > 165) {
            $warnings[] = 'Meta description is longer than 165 characters.';
        }

        return array('errors' => $errors, 'warnings' => $warnings);
    }
}
