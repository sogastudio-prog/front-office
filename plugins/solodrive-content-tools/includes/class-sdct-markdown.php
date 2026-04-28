<?php
if (!defined('ABSPATH')) {
    exit;
}

class SDCT_Markdown {
    public static function to_blocks($markdown) {
        $lines = preg_split('/\R/', trim($markdown));
        $html = array();
        $paragraph = array();
        $list = array();

        $flush_paragraph = function() use (&$paragraph, &$html) {
            if (!$paragraph) {
                return;
            }
            $text = trim(implode(' ', $paragraph));
            $html[] = '<!-- wp:paragraph --><p>' . self::inline($text) . '</p><!-- /wp:paragraph -->';
            $paragraph = array();
        };

        $flush_list = function() use (&$list, &$html) {
            if (!$list) {
                return;
            }
            $items = array();
            foreach ($list as $item) {
                $items[] = '<li>' . self::inline($item) . '</li>';
            }
            $html[] = '<!-- wp:list --><ul>' . implode('', $items) . '</ul><!-- /wp:list -->';
            $list = array();
        };

        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') {
                $flush_paragraph();
                $flush_list();
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.+)$/', $trim, $m)) {
                $flush_paragraph();
                $flush_list();
                $level = strlen($m[1]);
                $text = self::inline($m[2]);
                $html[] = '<!-- wp:heading {"level":' . $level . '} --><h' . $level . '>' . $text . '</h' . $level . '><!-- /wp:heading -->';
                continue;
            }

            if (preg_match('/^[-*]\s+(.+)$/', $trim, $m)) {
                $flush_paragraph();
                $list[] = $m[1];
                continue;
            }

            if (preg_match('/^>\s?(.+)$/', $trim, $m)) {
                $flush_paragraph();
                $flush_list();
                $html[] = '<!-- wp:quote --><blockquote class="wp-block-quote"><p>' . self::inline($m[1]) . '</p></blockquote><!-- /wp:quote -->';
                continue;
            }

            $paragraph[] = $trim;
        }

        $flush_paragraph();
        $flush_list();

        return implode("\n\n", $html);
    }

    private static function inline($text) {
        $text = esc_html($text);
        $text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/\[(.*?)\]\((.*?)\)/', '<a href="$2">$1</a>', $text);
        return $text;
    }
}
