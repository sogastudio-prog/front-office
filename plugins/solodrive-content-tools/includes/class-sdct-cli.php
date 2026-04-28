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

        /*
         * Imported Gutenberg pages already contain WordPress block comments.
         * Do not convert them as markdown or WordPress will display the block
         * markup as literal text.
         */
        if (strpos($body, '<!-- wp:') !== false) {
            return $body;
        }

        return SDCT_Markdown::to_blocks($body);
    }


}
