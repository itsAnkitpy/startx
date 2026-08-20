<?php

use App\Tenancy\BelongsToTenant;

/*
| Carried over from steps 1 and 2, where it was a convention nothing enforced.
|
| A client-owned model stamps the company from whatever is in scope. If the company
| were also a field a form may fill, a hand-crafted field in a submitted form would
| choose it instead. The database still refuses a row carrying another company's id, so
| this is not the last line of defence — but the application should never send that row
| in the first place, and "we remembered every time" is not a control.
|
| The check reads the models off disk rather than from a list, so a model added in a
| later module cannot skip it.
*/

it('never lets a submitted field choose the client company', function () {
    $models = collect(glob(app_path('Models/*.php')))
        ->map(fn (string $path) => 'App\\Models\\'.basename($path, '.php'))
        ->filter(fn (string $class) => in_array(BelongsToTenant::class, class_uses_recursive($class), true))
        ->values();

    // If this is ever empty the test below passes over nothing, which would be worse
    // than failing.
    expect($models)->not->toBeEmpty();

    $faults = $models
        ->filter(fn (string $class) => (new $class)->isFillable('tenant_id'))
        ->map(fn (string $class) => "{$class} lets a form fill tenant_id.")
        ->values()
        ->all();

    expect($faults)->toBe([]);
});
