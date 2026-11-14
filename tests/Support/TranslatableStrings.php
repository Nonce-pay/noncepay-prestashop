<?php

namespace NoncePay\PrestaShop\Tests\Support;

/**
 * The strings the module passes to PrestaShop's legacy translation system, keyed as translations/<iso>.php keys them.
 */
final class TranslatableStrings
{
    const MODULE = __DIR__ . '/../../noncepay';

    /** @return array<string,string> key => English text */
    public static function all(): array
    {
        $strings = [];
        $php = self::files('php');
        foreach ($php as $file) {
            preg_match_all("/->l\\(\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*(?:,\\s*'([a-z_]+)')?\\s*\\)/", (string) file_get_contents($file), $m, PREG_SET_ORDER);
            foreach ($m as $match) {
                $source = isset($match[2]) && $match[2] !== '' ? $match[2] : 'noncepay';
                self::add($strings, $source, $match[1]);
            }
        }
        preg_match_all("/\\\$t\\(\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*\\)/", (string) file_get_contents(self::MODULE . '/src/Settings.php'), $m);
        foreach ($m[1] as $literal) {
            self::add($strings, 'settings', $literal);
        }
        foreach (self::files('tpl') as $file) {
            preg_match_all("/\\{l\\s+s='((?:[^'\\\\]|\\\\.)*)'[^}]*mod='noncepay'/", (string) file_get_contents($file), $m);
            foreach ($m[1] as $literal) {
                self::add($strings, basename($file, '.tpl'), $literal);
            }
        }
        return $strings;
    }

    /** As Translate::getModuleTranslation() keys a string. */
    public static function key(string $source, string $text): string
    {
        return strtolower('<{noncepay}prestashop>' . $source) . '_' . md5(preg_replace("/\\\\*'/", "\\'", $text));
    }

    private static function add(array &$strings, string $source, string $literal): void
    {
        $text = str_replace(["\\'", '\\\\'], ["'", '\\'], $literal);
        $strings[self::key($source, $text)] = $text;
    }

    /** @return string[] */
    private static function files(string $extension): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::MODULE, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() === $extension && strpos($file->getPathname(), '/translations/') === false) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        return $files;
    }
}
