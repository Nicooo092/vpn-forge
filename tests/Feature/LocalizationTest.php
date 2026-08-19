<?php

namespace Tests\Feature;

use App\Filament\Pages\Backups;
use App\Filament\Resources\Blocklists\BlocklistResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Widgets\GettingStarted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    // getSteps() below reads the services/service_users tables to work out which
    // steps are already done.
    use RefreshDatabase;

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

    /**
     * The key-parity test above compares only keys, so a mass value-encoding
     * corruption (an authoring/export step re-encoding UTF-8 through cp1252 and
     * leaving every accent double-encoded) once shipped entirely green. Pin the
     * mojibake signature -- the tell-tale "Ã…"/"Â…"/"â€…" runs a double-encode
     * produces -- so any future value-encoding regression fails here instead of
     * rendering garbled accents on every non-English panel.
     */
    public function test_no_language_file_contains_double_encoded_utf8(): void
    {
        // Ã/Â followed by a Latin-1 high byte, or the â€ that leads every
        // double-encoded smart-quote/dash. Real accented text never matches:
        // it stores 'é' (one codepoint), not 'Ã' + '©'.
        $mojibake = '/[\x{00C3}\x{00C2}][\x{0080}-\x{00BF}]|\x{00E2}\x{20AC}/u';

        foreach (['fr', 'es', 'de', 'it', 'pt'] as $locale) {
            $catalogue = json_decode(file_get_contents(lang_path("{$locale}.json")), true);

            foreach ($catalogue as $key => $value) {
                $this->assertSame(
                    0,
                    preg_match($mojibake, (string) $value),
                    "{$locale}.json has a double-encoded (mojibake) value for key: {$key}"
                );
                $this->assertSame(
                    0,
                    preg_match($mojibake, (string) $key),
                    "{$locale}.json has a double-encoded (mojibake) key: {$key}"
                );
            }
        }
    }

    /**
     * Strings reached as __($variable) are invisible to a literal-only key
     * extractor. Regenerating the catalogues from extracted literals alone once
     * silently dropped every one of these, which turned the whole first-run
     * checklist back to English without failing a single test. This pins the
     * ones that are supplied from PHP and translated in a view.
     */
    public function test_dynamically_translated_strings_have_keys(): void
    {
        $catalogue = json_decode(file_get_contents(lang_path('fr.json')), true);

        $steps = (new GettingStarted)->getSteps();
        $this->assertNotEmpty($steps);

        foreach ($steps as $step) {
            foreach (['title', 'description', 'cta'] as $field) {
                $this->assertArrayHasKey(
                    $step[$field],
                    $catalogue,
                    "Getting-started {$field} has no translation key: {$step[$field]}"
                );
            }
        }

        // Suspension reasons are stored in English and translated at display.
        foreach (['suspended by hand', 'outside access hours', 'expired', 'data limit reached'] as $reason) {
            $this->assertArrayHasKey($reason, $catalogue, "Suspension reason has no translation key: {$reason}");
        }
    }
}
