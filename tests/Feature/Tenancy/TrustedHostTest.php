<?php

use Illuminate\Http\Middleware\TrustHosts;

/*
| The subdomain is what decides which client company a request belongs to, and the host
| name arrives inside the request — so the deployment has to say which host names it
| will answer to at all. Otherwise a request can claim to be for any client company.
|
| Nothing is readable without also signing in as somebody who belongs to that company,
| so this bites from the point accounts exist. The pattern is built from the same
| configured domain the subdomain lookup reads, so naming the real domain later is one
| environment value and no code.
*/

it('answers only for the configured domain and its client subdomains', function () {
    config()->set('tenancy.central_domain', 'startx.test');

    $patterns = app(TrustHosts::class)->hosts();

    expect($patterns)->toBe(['^(.+\.)?startx\.test$']);

    $matches = fn (string $host) => collect($patterns)
        ->contains(fn (string $pattern) => preg_match('#'.$pattern.'#i', $host) === 1);

    expect($matches('meridian.startx.test'))->toBeTrue()
        ->and($matches('vertex.startx.test'))->toBeTrue()
        ->and($matches('startx.test'))->toBeTrue()
        // A host somebody else controls, claiming to be one of our client companies.
        ->and($matches('meridian.startx.test.attacker.test'))->toBeFalse()
        ->and($matches('startx.test.attacker.test'))->toBeFalse();
});
