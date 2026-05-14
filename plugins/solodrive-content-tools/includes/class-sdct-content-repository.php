<?php
if (!defined('ABSPATH')) {
    exit;
}

class SDCT_Content_Repository {
    private $root_dir;
    private $content_dir;

    public function __construct($root_dir = null) {
        $this->root_dir = $root_dir ? rtrim($root_dir, '/') : $this->detect_root_dir();
        $this->content_dir = $this->root_dir . '/content';
    }

    public function root_dir() {
        return $this->root_dir;
    }

    public function content_dir() {
        return $this->content_dir;
    }

    public function pages_dir() {
        return $this->content_dir . '/pages';
    }

    public function content_dirs() {
        return array(
            $this->content_dir . '/pages',
            $this->content_dir . '/conversion',
            $this->content_dir . '/product',
            $this->content_dir . '/legal',
            $this->content_dir . '/utility',
        );
    }

    public function get_page_files() {
        $files = array();

        foreach ($this->content_dirs() as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            foreach (glob($dir . '/*.md') as $file) {
                $files[] = $file;
            }
        }

        $files = array_unique($files);
        sort($files);

        return $files;
    }

    public function load_pages() {
        $pages = array();

        foreach ($this->get_page_files() as $file) {
            $pages[] = $this->load_page($file);
        }

        return $pages;
    }

    public function load_page($file) {
        $raw = file_get_contents($file);
        $parsed = $this->parse_front_matter($raw);
        $meta = $parsed['meta'];
        $body = $parsed['body'];

        if (empty($meta['slug'])) {
            $meta['slug'] = sanitize_title(basename($file, '.md'));
        }

        if (empty($meta['title'])) {
            $meta['title'] = ucwords(str_replace('-', ' ', $meta['slug']));
        }

        if (empty($meta['status'])) {
            $meta['status'] = 'draft';
        }

        if (empty($meta['type'])) {
            $meta['type'] = $this->infer_type_from_file($file);
        }

        return array(
            'file' => $file,
            'meta' => $meta,
            'body' => $body,
        );
    }

    private function infer_type_from_file($file) {
        $normalized = str_replace('\\', '/', $file);

        if (strpos($normalized, '/content/conversion/') !== false) {
            return 'conversion';
        }

        if (strpos($normalized, '/content/product/') !== false) {
            return 'product';
        }

        if (strpos($normalized, '/content/legal/') !== false) {
            return 'legal';
        }

        if (strpos($normalized, '/content/utility/') !== false) {
            return 'utility';
        }

        return 'authority';
    }

    private function parse_front_matter($raw) {
        $meta = array();
        $body = $raw;

        if (preg_match('/\A---\s*\R(.*?)\R---\s*\R?(.*)\z/s', $raw, $matches)) {
            $meta = $this->parse_simple_yaml($matches[1]);
            $body = $matches[2];
        }

        return array(
            'meta' => $meta,
            'body' => trim($body),
        );
    }

    private function parse_simple_yaml($yaml) {
        $data  = array();
        $lines = preg_split('/\R/', $yaml);
        $count = count($lines);

        $list_key     = null;
        $current_item = null;

        for ($i = 0; $i < $count; $i++) {
            $raw     = $lines[$i];
            $trimmed = rtrim($raw);

            if ($trimmed === '') {
                continue;
            }

            $indent  = strlen($trimmed) - strlen(ltrim($trimmed));
            $trimmed = ltrim($trimmed);

            if ($trimmed === '' || strpos($trimmed, '#') === 0) {
                continue;
            }

            // Returned to root level while building a list — flush current item.
            if ($list_key !== null && $indent === 0) {
                if ($current_item !== null) {
                    $data[$list_key][] = $current_item;
                    $current_item      = null;
                }
                $list_key = null;
                // fall through: process this line as a root key:value
            }

            // List item start:  "  - key: value"
            if ($list_key !== null && preg_match('/^-\s+([A-Za-z0-9_\-]+):\s*(.*)$/', $trimmed, $m)) {
                if ($current_item !== null) {
                    $data[$list_key][] = $current_item;
                }
                $current_item = array();
                $sub_key      = sanitize_key(str_replace('-', '_', $m[1]));
                $current_item[$sub_key] = trim($m[2], "\"'");
                continue;
            }

            // Continuation of current list item:  "    key: value"  (no dash)
            if ($list_key !== null && $current_item !== null
                && preg_match('/^([A-Za-z0-9_\-]+):\s*(.*)$/', $trimmed, $m)
            ) {
                $sub_key = sanitize_key(str_replace('-', '_', $m[1]));
                $current_item[$sub_key] = trim($m[2], "\"'");
                continue;
            }

            // Root-level key:value pair.
            if (!preg_match('/^([A-Za-z0-9_\-]+):\s*(.*)$/', $trimmed, $m)) {
                continue;
            }

            $key   = sanitize_key(str_replace('-', '_', $m[1]));
            $value = trim($m[2]);
            $value = trim($value, "\"'");

            if ($value === '') {
                // Lookahead: enter list mode only if next non-empty line is indented and starts with "-".
                $is_list = false;
                for ($j = $i + 1; $j < $count; $j++) {
                    $peek = $lines[$j];
                    $peek_t = ltrim(rtrim($peek));
                    if ($peek_t === '' || strpos($peek_t, '#') === 0) {
                        continue;
                    }
                    $peek_indent = strlen(rtrim($peek)) - strlen(ltrim(rtrim($peek)));
                    if ($peek_indent > 0 && strpos(ltrim(rtrim($peek)), '-') === 0) {
                        $is_list = true;
                    }
                    break;
                }

                if ($is_list) {
                    $list_key     = $key;
                    $data[$key]   = array();
                    $current_item = null;
                    continue;
                }

                $data[$key] = '';
                continue;
            }

            // Inline array:  [a, b, c]
            if (strpos($value, '[') === 0 && substr($value, -1) === ']') {
                $items = trim($value, '[]');
                $value = $items === '' ? array() : array_map('trim', explode(',', $items));
            }

            $data[$key] = $value;
        }

        // Flush pending list item at end of input.
        if ($list_key !== null && $current_item !== null) {
            $data[$list_key][] = $current_item;
        }

        return $data;
    }

    private function detect_root_dir() {
        $dir = dirname(SDCT_PLUGIN_DIR, 2);

        for ($i = 0; $i < 5; $i++) {
            if (is_dir($dir . '/content')) {
                return $dir;
            }

            $parent = dirname($dir);

            if ($parent === $dir) {
                break;
            }

            $dir = $parent;
        }

        return dirname(SDCT_PLUGIN_DIR, 2);
    }
}
