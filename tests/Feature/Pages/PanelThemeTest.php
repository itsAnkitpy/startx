<?php

use App\Models\Tenant;
use Illuminate\Support\Facades\File;

/*
| The stylesheet the signed-in area is drawn with.
|
| Every class written on a Filament page in this project reaches the browser through the
| theme compiled for the admin panel and through nothing else. Dropping that link breaks
| no page and throws no error: the screens simply render as stacked plain text, which is
| what they did for four modules before this. So this asks the one question no screen
| test asks — does the page actually link the compiled stylesheet.
*/

it('links the compiled theme on a page in the signed-in area', function () {
    Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);

    // The built file's name carries a hash that changes on every build, so the name is
    // read from the build's own manifest rather than written down here. Its absence means
    // the theme was never compiled: run `npm run build`.
    $manifest = public_path('build/manifest.json');
    expect($manifest)->toBeFile();

    $theme = json_decode(file_get_contents($manifest), true)['resources/css/filament/admin/theme.css']['file'] ?? null;
    expect($theme)->not->toBeNull();

    $this->get('http://meridian.localhost/admin/login')
        ->assertOk()
        ->assertSee($theme, escape: false);
});

/*
| And the classes written on those pages, one level below the question above.
|
| The theme is compiled from what this project already uses, so a class nobody had used
| before is not in it and reaches the browser as nothing — no error, no broken page, just
| a rule that quietly does not apply. That is how a card's step name came to be near-black
| on a near-black card in dark mode: the class that would have turned it white was written
| and never compiled.
|
| So this reads the built stylesheet and every class this project writes by hand, and
| fails naming any that will not arrive. A class the file defines in its own `<style>`
| block counts as arriving — that is how the sign-in page is built.
*/
it('compiles every class the hand-written pages use', function () {
    $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
    $css = file_get_contents(public_path('build/'.$manifest['resources/css/filament/admin/theme.css']['file']));

    // Tailwind escapes a colon or a slash when it writes a selector, so `sm\:flex-row` in
    // the file is the class `sm:flex-row` on a page.
    preg_match_all('/\.((?:[\w-]|\\\\.)+)/', $css, $found);
    $compiled = array_flip(array_map(
        fn (string $selector): string => str_replace('\\', '', $selector),
        $found[1],
    ));

    $missing = [];

    foreach (File::allFiles(resource_path('views/filament')) as $view) {
        $source = $view->getContents();

        // What the file styles for itself, so the sign-in page's own rules count.
        preg_match_all('/<style>(.*?)<\/style>/s', $source, $blocks);
        preg_match_all('/\.([\w-]+)/', implode('', $blocks[1]), $own);
        $ownClasses = array_flip($own[1]);

        preg_match_all('/class="([^"]*)"/', $source, $attributes);

        foreach ($attributes[1] as $attribute) {
            foreach (preg_split('/\s+/', trim($attribute), -1, PREG_SPLIT_NO_EMPTY) as $class) {
                if (isset($compiled[$class]) || isset($ownClasses[$class])) {
                    continue;
                }

                $missing[] = $view->getFilename().' uses ['.$class.'], which the theme does not carry.';
            }
        }
    }

    expect(array_values(array_unique($missing)))->toBe([]);
});
