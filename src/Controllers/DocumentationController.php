<?php
declare(strict_types=1);

namespace VazinCMS\Controllers;

use VazinCMS\Security;
use VazinCMS\Version;

final class DocumentationController
{
    public function page(string $section = 'docs'): void
    {
        $allowed = [
            'docs',
            'download',
            'developers',
            'extensions',
            'themes',
            'changelog',
        ];

        if (!in_array($section, $allowed, true)) {
            http_response_code(404);
            return;
        }

        $root = dirname(__DIR__, 2);

        $data = [
            'section' => $section,
            'version' => Version::current(),
            'title' => $this->title($section),
            'install' => $this->safeRead($root . '/docs/INSTALL-FA.md'),
            'extensions' => $this->safeRead($root . '/docs/EXTENSIONS-FA.md'),
            'upgrade' => $this->safeRead($root . '/docs/UPGRADE-ROLLBACK-FA.md'),
            'changelog' => $this->safeRead($root . '/CHANGELOG.md'),
        ];

        $this->render($root, $data);
    }

    public function openApi(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        readfile(dirname(__DIR__, 2) . '/public/openapi-v3.json');
    }

    private function title(string $section): string
    {
        return match ($section) {
            'download' => 'Download VazinCMS',
            'developers' => 'VazinCMS Developer Center',
            'extensions' => 'VazinCMS Extensions',
            'themes' => 'VazinCMS Themes',
            'changelog' => 'VazinCMS Changelog',
            default => 'VazinCMS Documentation',
        };
    }

    private function safeRead(string $file): string
    {
        if (!is_file($file)) {
            return '';
        }

        $content = file_get_contents($file);
        return is_string($content) ? $content : '';
    }

    private function render(string $root, array $data): void
    {
        extract($data, EXTR_SKIP);
        require $root . '/views/public/documentation.php';
    }
}
