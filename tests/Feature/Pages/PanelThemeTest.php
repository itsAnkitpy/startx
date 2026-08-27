<?php

use App\Models\Tenant;

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
