<?php

namespace Splicewire\Beam\Exec\Tests\Wire;

use Illuminate\Translation\MessageSelector;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Splicewire\Beam\Exec\BeamExecServiceProvider;
use Splicewire\Beam\Exec\Localization\ExecError;
use Splicewire\Beam\Exec\Tests\TestCase;
use Splicewire\Beam\Localization\BeamTranslator;
use Splicewire\Beam\Localization\PseudoLocale;
use Splicewire\Beam\Localization\TextNamespaces;

/** Uses the original TestCase providers and actual allocator; adds no provider, hint or binding. */
class NamespaceAllocationTest extends TestCase
{
    public function test_allocator_owns_every_exec_catalog_lookup(): void
    {
        $translator = app('translator');
        $this->assertInstanceOf(BeamTranslator::class, $translator);
        $allocator = app(TextNamespaces::class);
        $owner = $allocator->ownerOfClass(BeamExecServiceProvider::class);
        $this->assertSame('splicewire/laravel-beam-exec', $owner->package);
        $this->assertSame('beam-exec::', $owner->keyPrefix());
        $this->assertFalse($owner->isRefused());
        $root = dirname(__DIR__, 2);
        $lines = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/lang/en', RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $group = substr($file->getPathname(), strlen($root.'/lang/en/'), -4);
                $this->flatten(require $file->getPathname(), 'beam-exec::'.$group, $lines);
            }
        }
        $this->assertNotEmpty($lines);
        $observations = [];
        foreach ($lines as $key => $line) {
            preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $line, $matches);
            $parameters = [];
            foreach (array_unique($matches[1]) as $name) {
                $parameters[$name] = 'RAW_'.$name.'_VALUE';
            }
            foreach (str_contains($line, '|') ? [1, 2] : [null] as $count) {
                $replace = $parameters;
                if ($count !== null) {
                    $replace['count'] = $count;
                }
                $selected = $count === null ? $line : (new MessageSelector)->choose($line, $count, 'en');
                foreach (['en', 'en-XA'] as $locale) {
                    config(['beam.localization.pseudo' => true]);
                    $this->app->setLocale($locale);
                    $expected = $locale === 'en' ? $selected : PseudoLocale::transform($selected);
                    foreach ($replace as $name => $value) {
                        $expected = str_replace(':'.$name, (string) $value, $expected);
                    }
                    $actual = $count === null ? __($key, $replace) : trans_choice($key, $count, $replace);
                    $this->assertSame($expected, $actual, $key.' '.$locale);
                    if ($locale === 'en-XA') {
                        $this->assertTrue(PseudoLocale::isMarked($actual), $key);
                    }
                    foreach ($replace as $value) {
                        $this->assertStringContainsString((string) $value, $actual, $key.' preserves raw parameters');
                    }
                    $observations[] = ['key' => $key, 'locale' => $locale, 'count' => $count];
                }
            }
        }
        // Derived enum keys have declared defaults rather than lang files; exercise the same owner too.
        foreach (ExecError::cases() as $case) {
            $this->assertStringStartsWith('beam-exec::', $case->translationKey());
            preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $case->defaultMessage(), $matches);
            $params = [];
            foreach (array_unique($matches[1]) as $name) {
                $params[$name] = 'RAW_'.$name.'_VALUE';
            }
            foreach (['en', 'en-XA'] as $locale) {
                $this->app->setLocale($locale);
                $expected = $locale === 'en' ? $case->defaultMessage() : PseudoLocale::transform($case->defaultMessage());
                foreach ($params as $name => $value) {
                    $expected = str_replace(':'.$name, $value, $expected);
                }
                $this->assertSame($expected, $case->message($params), $case->translationKey().' '.$locale);
                $observations[] = ['key' => $case->translationKey(), 'locale' => $locale, 'count' => null];
            }
        }
        if ($report = getenv('EXEC_FU1_CATALOG_REPORT')) {
            file_put_contents($report, json_encode(['catalogKeys' => array_keys($lines), 'enumKeys' => array_map(fn (ExecError $case) => $case->translationKey(), ExecError::cases()), 'observations' => $observations], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
        }
        // Architectural regression: all lookups above used the original boot; package code may not bypass its allocator.
        $tokens = token_get_all(file_get_contents($root.'/src/Concerns/WiresExecText.php'));
        $calls = array_map(fn (array $token) => strtolower($token[1]), array_filter($tokens, fn ($token) => is_array($token) && $token[0] === T_STRING));
        $this->assertNotContains('loadtranslationsfrom', $calls, 'Exec must leave namespace hints to Beam TextNamespaces.');
        $this->assertNotContains('addnamespace', $calls, 'Exec must leave namespace hints to Beam TextNamespaces.');
    }

    private function flatten(array $values, string $prefix, array &$lines): void
    {
        foreach ($values as $name => $value) {
            if (is_array($value)) {
                $this->flatten($value, $prefix.'.'.$name, $lines);
            } else {
                $this->assertIsString($value, $prefix.'.'.$name);
                $lines[$prefix.'.'.$name] = $value;
            }
        }
    }
}
