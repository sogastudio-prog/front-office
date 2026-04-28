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

    public function pages_dir() {
        return $this->content_dir . '/pages';
    }

    public function get_page_files() {
        $dir = $this->pages_dir();
        if (!is_dir($dir)) {
            return array();
        }
        $files = glob($dir . '/*.md');
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

        return array(
            'file' => $file,
            'meta' => $meta,
            'body' => $body,
        );
    }

    private function parse_front_matter($raw) {
        $meta = array();
        $body = $raw;

        if (preg_match('/\A---\s*\R(.*?)\R---\s*\R?(.*)\z/s', $raw, $matches)) {
            $meta = $this->parse_simple_yaml($matches[1]);
            $body = $matches[2];
        }

        return array('meta' => $meta, 'body' => trim($body));
    }

    private function parse_simple_yaml($yaml) {
        $data = array();
        $lines = preg_split('/\R/', $yaml);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            if (!preg_match('/^([A-Za-z0-9_\-]+):\s*(.*)$/', $line, $m)) {
                continue;
            }
            $key = sanitize_key(str_replace('-', '_', $m[1]));
            $value = trim($m[2]);
            $value = trim($value, "\"'");
            if (strpos($value, '[') === 0 && substr($value, -1) === ']') {
                $items = trim($value, '[]');
                $value = $items === '' ? array() : array_map('trim', explode(',', $items));
            }
            $data[$key] = $value;
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
