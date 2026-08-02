<?php

namespace Tests\Feature;

use App\Filament\Pages\Backups;
use App\Filament\Resources\Blocklists\BlocklistResource;
use App\Filament\Resources\Services\ServiceResource;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    public function test_navigation_labels_translate_with_the_locale(): void
    {
        app()->setLocale('fr');
        $this->assertSame('Listes de blocage', BlocklistResource::getNavigationLabel());
        $this->assertSame('Sauvegardes', Backups::getNavigationLabel());
        $this->assertSame('Services', ServiceResource::getNavigationLabel());

        app()->setLocale('es');
        $this->assertSame('Copias de seguridad', Backups::getNavigationLabel());

        app()->setLocale('de');
        $this->assertSame('Sperrlisten', BlocklistResource::getNavigationLabel());
    }

    public function test_it_falls_back_to_english_for_an_untranslated_locale(): void
    {
        // No lang/en.json -> the key (the English string) is returned as-is.
        app()->setLocale('en');
        $this->assertSame('Backups', Backups::getNavigationLabel());
        $this->assertSame('Blocklists', BlocklistResource::getNavigationLabel());
    }

    public function test_every_shipped_language_covers_the_same_keys(): void
    {
        $reference = array_keys(json_decode(file_get_contents(lang_path('fr.json')), true));

        foreach (['es', 'de', 'it', 'pt'] as $locale) {
            $keys = array_keys(json_decode(file_get_contents(lang_path("{$locale}.json")), true));
            $this->assertSame($reference, $keys, "{$locale}.json is missing keys present in fr.json");
        }
    }
}
