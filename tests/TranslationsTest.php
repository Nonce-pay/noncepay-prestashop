<?php

namespace NoncePay\PrestaShop\Tests;

use NoncePay\PrestaShop\Tests\Support\TranslatableStrings;
use PHPUnit\Framework\TestCase;

final class TranslationsTest extends TestCase
{
    public function testEveryStringHasAFrenchTranslation(): void
    {
        $_MODULE = [];
        require __DIR__ . '/../noncepay/translations/fr.php';
        $strings = TranslatableStrings::all();
        $this->assertGreaterThan(40, count($strings));

        $missing = array_diff_key($strings, array_filter($_MODULE));
        $this->assertSame([], $missing, 'Missing in translations/fr.php');
        $this->assertSame([], array_diff_key($_MODULE, $strings), 'Unused in translations/fr.php');

        foreach ($strings as $key => $english) {
            $this->assertSame(substr_count($english, '%s'), substr_count($_MODULE[$key], '%s'), $english);
        }
    }
}
