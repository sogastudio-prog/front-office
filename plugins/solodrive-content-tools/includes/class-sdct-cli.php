<?php
if (!defined('ABSPATH')) {
    exit;
}

class SDCT_CLI_Command {
    public function list($args, $assoc_args) {
        $repo = new SDCT_Content_Repository($this->root_arg($assoc_args));
        $pages = $repo->load_pages();
        if (!$pages) {
            WP_CLI::warning('No markdown pages found in ' . $repo->pages_dir());
            return;
        }
        foreach ($pages as $page) {
            $type = isset($page['meta']['type']) ? $page['meta']['type'] : 'authority';
            WP_CLI::line($page['meta']['slug'] . ' | ' . $page['meta']['title'] . ' | ' . $page['meta']['status'] . ' | ' . $type);
        }
    }

    public function validate($args, $assoc_args) {
        $repo = new SDCT_Content_Repository($this->root_arg($assoc_args));
        $pages = $this->filtered_pages($repo, $assoc_args);
        $error_count = 0;
        foreach ($pages as $page) {
            $result = SDCT_Validator::validate_page($page);
            $slug = $page['meta']['slug'];
            if (!$result['errors'] && !$result['warnings']) {
                WP_CLI::success($slug . ' passed.');
                continue;
            }
            foreach ($result['errors'] as $error) {
                $error_count++;
                WP_CLI::warning($slug . ': ERROR: ' . $error);
            }
            foreach ($result['warnings'] as $warning) {
                WP_CLI::line($slug . ': WARNING: ' . $warning);
            }
        }
        if ($error_count > 0) {
            WP_CLI::halt(1);
        }
        WP_CLI::success('Validation complete.');
    }

    public function sync($args, $assoc_args) {
        $repo = new SDCT_Content_Repository($this->root_arg($assoc_args));
        $pages = $this->filtered_pages($repo, $assoc_args);
        $dry_run = isset($assoc_args['dry-run']);

        foreach ($pages as $page) {
            $result = SDCT_Validator::validate_page($page);
            if ($result['errors']) {
                foreach ($result['errors'] as $error) {
                    WP_CLI::warning($page['meta']['slug'] . ': ' . $error);
                }
                WP_CLI::halt(1);
            }

            $postarr = $this->page_to_postarr($page);
            $existing = get_page_by_path($postarr['post_name'], OBJECT, 'page');
            if ($existing) {
                $postarr['ID'] = $existing->ID;
            }

            if ($dry_run) {
                WP_CLI::line(($existing ? 'Would update: ' : 'Would create: ') . $postarr['post_name']);
                continue;
            }

            $post_id = $existing ? wp_update_post($postarr, true) : wp_insert_post($postarr, true);
            if (is_wp_error($post_id)) {
                WP_CLI::error($post_id->get_error_message());
            }
            $this->update_meta($post_id, $page['meta']);
            WP_CLI::success(($existing ? 'Updated: ' : 'Created: ') . $postarr['post_name']);
        }
    }

    public function diff($args, $assoc_args) {
        $assoc_args['dry-run'] = true;
        $this->sync($args, $assoc_args);
    }

    public function export($args, $assoc_args) {
        $repo = new SDCT_Content_Repository($this->root_arg($assoc_args));
        $out_dir = $repo->pages_dir();
        if (!is_dir($out_dir)) {
            wp_mkdir_p($out_dir);
        }
        $query = new WP_Query(array('post_type' => 'page', 'post_status' => 'any', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC'));
        foreach ($query->posts as $post) {
            $file = $out_dir . '/' . $post->post_name . '.md';
            if (file_exists($file) && !isset($assoc_args['force'])) {
                WP_CLI::line('Skipped existing file: ' . basename($file));
                continue;
            }
            $front = array(
                'title: ' . $post->post_title,
                'slug: ' . $post->post_name,
                'status: ' . $post->post_status,
                'meta_title: ' . get_post_meta($post->ID, '_sdct_meta_title', true),
                'meta_description: ' . get_post_meta($post->ID, '_sdct_meta_description', true),
                'cta: ' . get_post_meta($post->ID, '_sdct_cta', true),
            );
            $body = wp_strip_all_tags($post->post_content);
            file_put_contents($file, "---\n" . implode("\n", $front) . "\n---\n\n" . $body . "\n");
            WP_CLI::success('Exported: ' . basename($file));
        }
    }


    /**
     * Migrate authority markdown into sd_authority meta records without touching native public pages.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Show intended changes only. Default when --execute is absent.
     *
     * [--execute]
     * : Write sd_authority posts and _sdct_* meta.
     *
     * [--slug=<slug>]
     * : Limit migration to one authority markdown file.
     *
     * [--root=<path>]
     * : Optional repository root. Defaults to auto-detected front-office root.
     */
    public function migrate_authority($args, $assoc_args) {
        $repo = new SDCT_Content_Repository($this->root_arg($assoc_args));
        $pages = array_values(array_filter($repo->load_pages(), function($page) {
            $type = isset($page['meta']['type']) ? $page['meta']['type'] : 'authority';
            $file = isset($page['file']) ? str_replace('\\', '/', $page['file']) : '';
            return $type === 'authority' && strpos($file, '/content/pages/') !== false;
        }));

        if (isset($assoc_args['slug'])) {
            $slug = sanitize_title($assoc_args['slug']);
            $pages = array_values(array_filter($pages, function($page) use ($slug) {
                return isset($page['meta']['slug']) && $page['meta']['slug'] === $slug;
            }));
            if (!$pages) {
                WP_CLI::error('No authority content file found for slug: ' . $slug);
            }
        }

        $execute = isset($assoc_args['execute']);
        WP_CLI::line(($execute ? 'EXECUTE' : 'DRY RUN') . ': found ' . count($pages) . ' authority markdown files.');

        foreach ($pages as $page) {
            $meta = isset($page['meta']) ? $page['meta'] : array();
            $body = isset($page['body']) ? $page['body'] : '';

            $slug = sanitize_title($meta['slug'] ?? basename($page['file'], '.md'));
            $title = sanitize_text_field($meta['title'] ?? ucwords(str_replace('-', ' ', $slug)));
            $status = sanitize_key($meta['status'] ?? 'draft');
            if (!in_array($status, array('publish', 'draft', 'private', 'pending'), true)) {
                $status = 'draft';
            }

            $existing_authority = get_page_by_path($slug, OBJECT, 'sd_authority');
            $existing_page = get_page_by_path($slug, OBJECT, 'page');
            $legacy = self::extract_legacy_authority_sections($body);
            $sections = self::parse_authority_body_sections($body);
            $answer = self::authority_answer_from_body($body, $meta);
            $canonical = home_url('/' . $slug . '/');

            WP_CLI::line(sprintf(
                '%s %s: %s sections, %s related links%s',
                $existing_authority ? 'Update' : 'Create',
                $slug,
                count($sections),
                count($legacy['related']),
                $existing_page ? ' (native page exists: ' . $existing_page->ID . ', left unchanged)' : ''
            ));

            if (!$execute) {
                continue;
            }

            $postarr = array(
                'post_type'    => 'sd_authority',
                'post_title'   => $title,
                'post_name'    => $slug,
                'post_status'  => $status,
                'post_content' => '',
            );
            if ($existing_authority) {
                $postarr['ID'] = $existing_authority->ID;
            }

            $post_id = $existing_authority ? wp_update_post($postarr, true) : wp_insert_post($postarr, true);
            if (is_wp_error($post_id)) {
                WP_CLI::warning($slug . ': ' . $post_id->get_error_message());
                continue;
            }

            update_post_meta($post_id, '_sdct_page_title', $title);
            update_post_meta($post_id, '_sdct_topic', sanitize_text_field($meta['primary_topic'] ?? ''));
            update_post_meta($post_id, '_sdct_topic_cluster', sanitize_text_field($meta['audience'] ?? ''));
            update_post_meta($post_id, '_sdct_answer', $answer);
            update_post_meta($post_id, '_sdct_body_sections', wp_json_encode($sections));
            update_post_meta($post_id, '_sdct_faq', wp_json_encode(array()));
            update_post_meta($post_id, '_sdct_related_pages', wp_json_encode($legacy['related']));
            update_post_meta($post_id, '_sdct_meta_description', sanitize_textarea_field($meta['meta_description'] ?? $answer));
            update_post_meta($post_id, '_sdct_canonical_url', esc_url_raw($canonical));
            update_post_meta($post_id, '_sdct_schema_type', sanitize_text_field($meta['schema_type'] ?? 'Article'));
            update_post_meta($post_id, '_sdct_author_name', 'SoloDrive');
            update_post_meta($post_id, '_sdct_published_date', sanitize_text_field($meta['last_reviewed'] ?? ''));
            update_post_meta($post_id, '_sdct_last_reviewed', sanitize_text_field($meta['last_reviewed'] ?? ''));
            update_post_meta($post_id, '_sdct_review_required', !empty($meta['review_required']) ? '1' : '0');
            update_post_meta($post_id, '_sdct_noindex', $status === 'publish' ? '0' : '1');
            update_post_meta($post_id, '_sdct_cta_heading', $legacy['cta']['heading'] ?? 'Build the direct path.');
            update_post_meta($post_id, '_sdct_cta_body', $legacy['cta']['body'] ?? 'Give riders who already trust you a professional way to book you again.');
            update_post_meta($post_id, '_sdct_cta_label', $legacy['cta']['label'] ?? 'Get Your Booking Page');
            update_post_meta($post_id, '_sdct_cta_url', $legacy['cta']['url'] ?? '/start/');

            WP_CLI::success('Migrated authority meta: ' . $slug . ' -> post ID ' . $post_id);
        }

        WP_CLI::success($execute ? 'Authority migration complete.' : 'Dry run complete. Re-run with --execute to write changes.');
    }

    public function links($args, $assoc_args) {
        $repo = new SDCT_Content_Repository($this->root_arg($assoc_args));
        $pages = $repo->load_pages();
        $slugs = array_map(function($page) { return $page['meta']['slug']; }, $pages);
        foreach ($pages as $page) {
            $slug = $page['meta']['slug'];
            $body = $page['body'];
            $found = array();
            foreach ($slugs as $target) {
                if ($target !== $slug && strpos($body, '/' . $target) !== false) {
                    $found[] = $target;
                }
            }
            if (!$found) {
                WP_CLI::warning($slug . ': no internal links to known content pages.');
            } else {
                WP_CLI::line($slug . ': links to ' . implode(', ', $found));
            }
        }
    }


    private static function markdown_inline_to_html($text) {
        $text = esc_html($text);
        $text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace_callback('/\[(.*?)\]\((.*?)\)/', function($m) {
            return '<a href="' . esc_url($m[2]) . '">' . esc_html($m[1]) . '</a>';
        }, $text);
        return $text;
    }

    private static function authority_markdown_to_html($markdown) {
        $lines = preg_split('/\R/', trim((string) $markdown));
        $html = array();
        $para = array();
        $list = array();

        $flush_para = function() use (&$html, &$para) {
            if (!$para) {
                return;
            }
            $html[] = '<p>' . self::markdown_inline_to_html(trim(implode(' ', $para))) . '</p>';
            $para = array();
        };

        $flush_list = function() use (&$html, &$list) {
            if (!$list) {
                return;
            }
            $items = array_map(function($item) {
                return '<li>' . self::markdown_inline_to_html($item) . '</li>';
            }, $list);
            $html[] = '<ul>' . implode('', $items) . '</ul>';
            $list = array();
        };

        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') {
                $flush_para();
                $flush_list();
                continue;
            }
            if (preg_match('/^[-*]\s+(.+)$/', $trim, $m)) {
                $flush_para();
                $list[] = $m[1];
                continue;
            }
            if (preg_match('/^###\s+(.+)$/', $trim, $m)) {
                $flush_para();
                $flush_list();
                $html[] = '<h3>' . self::markdown_inline_to_html($m[1]) . '</h3>';
                continue;
            }
            $para[] = $trim;
        }

        $flush_para();
        $flush_list();

        return implode("\n", $html);
    }

    private static function extract_legacy_authority_sections($body) {
        $related = array();
        $cta = array();

        if (preg_match('/<section[^>]*sd-related-pages[^>]*>(.*?)<\/section>/is', $body, $m)) {
            if (preg_match_all('/<a\s+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $m[1], $links, PREG_SET_ORDER)) {
                foreach ($links as $link) {
                    $related[] = array(
                        'label' => sanitize_text_field(wp_strip_all_tags(html_entity_decode($link[2]))),
                        'url'   => esc_url_raw(html_entity_decode($link[1])),
                    );
                }
            }
        }

        if (preg_match('/<section[^>]*sd-final-cta[^>]*>(.*?)<\/section>/is', $body, $m)) {
            if (preg_match('/<h2[^>]*>(.*?)<\/h2>/is', $m[1], $h)) {
                $cta['heading'] = sanitize_text_field(wp_strip_all_tags(html_entity_decode($h[1])));
            }
            if (preg_match('/<p[^>]*>(.*?)<\/p>/is', $m[1], $para)) {
                $cta['body'] = sanitize_textarea_field(wp_strip_all_tags(html_entity_decode($para[1])));
            }
            if (preg_match('/<a\s+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $m[1], $a)) {
                $cta['url'] = esc_url_raw(html_entity_decode($a[1]));
                $cta['label'] = sanitize_text_field(wp_strip_all_tags(html_entity_decode($a[2])));
            }
        }

        return array('related' => $related, 'cta' => $cta);
    }

    private static function parse_authority_body_sections($body) {
        $body_without_html_sections = preg_replace('/<section\s+class=["\']sd-section.*$/is', '', (string) $body);
        $body_without_html_sections = trim($body_without_html_sections);
        $parts = preg_split('/^##\s+(.+)$/m', $body_without_html_sections, -1, PREG_SPLIT_DELIM_CAPTURE);
        $sections = array();

        for ($i = 1; $i < count($parts); $i += 2) {
            $heading = trim($parts[$i] ?? '');
            $content = trim($parts[$i + 1] ?? '');
            if ($heading === '' && $content === '') {
                continue;
            }
            $sections[] = array(
                'heading'       => sanitize_text_field($heading),
                'heading_level' => 'h2',
                'body'          => wp_kses_post(self::authority_markdown_to_html($content)),
            );
        }

        return $sections;
    }

    private static function authority_answer_from_body($body, $meta) {
        if (!empty($meta['summary'])) {
            return sanitize_textarea_field($meta['summary']);
        }
        $body = preg_replace('/<section\s+class=["\']sd-section.*$/is', '', (string) $body);
        $parts = preg_split('/^##\s+.+$/m', trim($body), 2);
        $intro = $parts[0] ?? '';
        $paragraphs = preg_split('/\R\s*\R/', trim($intro));
        return sanitize_textarea_field(trim($paragraphs[0] ?? ''));
    }

    private function filtered_pages($repo, $assoc_args) {
        $pages = $repo->load_pages();
        if (isset($assoc_args['slug'])) {
            $slug = sanitize_title($assoc_args['slug']);
            $pages = array_values(array_filter($pages, function($page) use ($slug) {
                return $page['meta']['slug'] === $slug;
            }));
            if (!$pages) {
                WP_CLI::error('No content file found for slug: ' . $slug);
            }
        }
        return $pages;
    }

    private function page_to_postarr($page) {
        $meta = $page['meta'];
        return array(
            'post_type' => 'page',
            'post_title' => $meta['title'],
            'post_name' => $meta['slug'],
            'post_status' => $meta['status'],
            'post_content' => self::render_content($page),
        );
    }

    private function update_meta($post_id, $meta) {
        $map = array(
            'type',
            'template',
            'meta_title',
            'meta_description',
            'summary',
            'audience',
            'primary_topic',
            'cta',
            'primary_cta',
            'secondary_cta',
            'button_text',
            'button_url',
            'conversion_goal',
            'schema_type',
            'last_reviewed',
            'review_required',
        );
        foreach ($map as $key) {
            if (isset($meta[$key])) {
                update_post_meta($post_id, '_sdct_' . $key, $meta[$key]);
            }
        }
    }

    private function root_arg($assoc_args) {
        return isset($assoc_args['root']) ? $assoc_args['root'] : null;
    }

    private static function render_content($page) {
        $body = isset($page['body']) ? $page['body'] : '';
        $meta = isset($page['meta']) ? $page['meta'] : array();

        $type = !empty($meta['type']) ? sanitize_html_class($meta['type']) : 'authority';
        $template = !empty($meta['template']) ? sanitize_html_class($meta['template']) : $type;

        /*
         * Gutenberg pages already contain WordPress block comments.
         * Clean managed pages may also contain intentional HTML sections/divs.
         * Do not convert those as markdown or WordPress will display markup
         * as literal text.
         */
        if (self::body_is_raw_markup($body)) {
            $content = $body;
        } else {
            $content = self::render_mixed_markdown_content($body);
        }

        return self::wrap_managed_content($content, $type, $template);
    }

    private static function render_mixed_markdown_content($body): string {
        $body = trim((string) $body);

        if ($body === '') {
            return '';
        }

        /*
         * Authority pages may be Markdown articles with managed raw HTML
         * sections appended at the bottom. Convert the Markdown article body,
         * but preserve the managed HTML sections as HTML.
         */
        $first_managed_section = strpos($body, '<section class="sd-section');

        if ($first_managed_section === false) {
            return SDCT_Markdown::to_blocks($body);
        }

        $markdown_part = trim(substr($body, 0, $first_managed_section));
        $html_part = trim(substr($body, $first_managed_section));

        $content = '';

        if ($markdown_part !== '') {
            $content .= SDCT_Markdown::to_blocks($markdown_part);
        }

        if ($html_part !== '') {
            if ($content !== '') {
                $content .= "\n\n";
            }

            $content .= $html_part;
        }

        return $content;
    }

    private static function body_is_raw_markup($body): bool {
        $body = trim((string) $body);

        if ($body === '') {
            return false;
        }

        /*
         * Gutenberg block pages are already fully block-serialized.
         * Keep them raw so WordPress does not display block comments as text.
         */
        if (strpos($body, '<!-- wp:') !== false) {
            return true;
        }

        /*
         * Treat a page as raw markup only when the body begins as HTML.
         * Authority pages may be mostly Markdown with managed HTML sections
         * appended at the bottom; those must still pass through Markdown
         * conversion or headings like ## will render as literal text.
         */
        return (bool) preg_match('/^\\s*</', $body);
    }


    private static function wrap_managed_content($content, $type, $template) {
        $classes = array(
            'sd-managed-page',
            'sd-managed-page--' . $type,
            'sd-managed-template--' . $template,
        );

        $class_attr = esc_attr(implode(' ', array_filter($classes)));

        /*
         * Use a native Gutenberg group wrapper so the block editor continues
         * to understand the page structure after sync.
         */
        return '<!-- wp:group {"className":"' . $class_attr . '","layout":{"type":"constrained"}} -->' . "\n"
            . '<div class="wp-block-group ' . $class_attr . '">' . "\n"
            . $content . "\n"
            . '</div>' . "\n"
            . '<!-- /wp:group -->';
    }


}
