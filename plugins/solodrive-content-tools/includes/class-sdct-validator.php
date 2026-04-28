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
        $type = !empty($meta['type']) ? sanitize_key($meta['type']) : 'authority';

        switch ($type) {
            case 'conversion':
                self::require_fields($meta, array(
                    'title',
                    'slug',
                    'meta_title',
                    'meta_description',
                    'summary',
                    'primary_cta',
                    'schema_type',
                    'last_reviewed',
                ), $errors);

                self::warn_without_h2($body, $warnings);
                self::warn_long_meta_description($meta, $warnings);
                self::warn_without_conversion_path($meta, $body, $warnings);
                break;

            case 'product':
                self::require_fields($meta, array(
                    'title',
                    'slug',
                    'meta_title',
                    'meta_description',
                    'summary',
                    'audience',
                    'primary_topic',
                    'primary_cta',
                    'schema_type',
                    'last_reviewed',
                ), $errors);

                self::warn_without_h2($body, $warnings);
                self::warn_long_meta_description($meta, $warnings);
                self::warn_without_request_access($meta, $body, $warnings);
                break;

            case 'legal':
                self::require_fields($meta, array(
                    'title',
                    'slug',
                    'meta_title',
                    'meta_description',
                    'summary',
                    'schema_type',
                    'last_reviewed',
                ), $errors);

                self::warn_without_h2($body, $warnings);
                self::warn_long_meta_description($meta, $warnings);
                break;

            case 'utility':
                self::require_fields($meta, array(
                    'title',
                    'slug',
                    'meta_title',
                    'meta_description',
                    'summary',
                    'schema_type',
                    'last_reviewed',
                ), $errors);

                self::warn_long_meta_description($meta, $warnings);
                break;

            case 'authority':
            default:
                self::require_fields($meta, array(
                    'title',
                    'slug',
                    'meta_title',
                    'meta_description',
                    'summary',
                    'audience',
                    'primary_topic',
                    'cta',
                    'schema_type',
                    'last_reviewed',
                ), $errors);

                /*
                 * Do not require a body H1.
                 *
                 * WordPress/Astra already renders the page title from post_title.
                 * Requiring "# Page Title" inside markdown causes duplicate visible titles.
                 */
                self::warn_without_h2($body, $warnings);
                self::warn_long_meta_description($meta, $warnings);
                self::warn_without_request_access($meta, $body, $warnings);
                break;
        }

        return array(
            'errors' => $errors,
            'warnings' => $warnings,
        );
    }

    private static function require_fields($meta, $fields, &$errors) {
        foreach ($fields as $field) {
            if (empty($meta[$field])) {
                $errors[] = 'Missing front matter: ' . $field;
            }
        }
    }

    private static function warn_without_h2($body, &$warnings) {
        if (!preg_match('/^##\s+.+/m', $body)) {
            $warnings[] = 'No H2 sections found.';
        }
    }

    private static function warn_long_meta_description($meta, &$warnings) {
        if (isset($meta['meta_description']) && strlen($meta['meta_description']) > 165) {
            $warnings[] = 'Meta description is longer than 165 characters.';
        }
    }

    private static function warn_without_request_access($meta, $body, &$warnings) {
        $cta = isset($meta['cta']) ? $meta['cta'] : '';
        $primary_cta = isset($meta['primary_cta']) ? $meta['primary_cta'] : '';

        if (
            stripos($body, 'request access') === false
            && $cta !== 'request-access'
            && $primary_cta !== 'request-access'
        ) {
            $warnings[] = 'No obvious request-access CTA.';
        }
    }

    private static function warn_without_conversion_path($meta, $body, &$warnings) {
        $primary_cta = isset($meta['primary_cta']) ? $meta['primary_cta'] : '';
        $button_url = isset($meta['button_url']) ? $meta['button_url'] : '';

        if (
            stripos($body, 'request access') === false
            && stripos($body, 'booking page') === false
            && empty($primary_cta)
            && empty($button_url)
        ) {
            $warnings[] = 'No obvious conversion path.';
        }
    }
}
