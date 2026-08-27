<?php

use App\Models\Tenant;

/*
| The three pages somebody can reach before signing in: the front page, the sign-in page
| on a client company's own address, and the page a wrong address gets.
|
| They are tested through real requests rather than by rendering a view, because two of
| the things that would break them are invisible to a view test. All three share one
| brand component, so a mistake in it breaks all three at once. And none of them may
| depend on a front-end build: the signed-in area now compiles a stylesheet, but these
| three pages carry their own styles and must keep rendering with nothing built.
*/

it('offers the same sign-in link on the bare domain and on a company address', function () {
    Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);

    // One button, one address, so it cannot be right in one place and broken in the
    // other. What differs is the panel it leads to, not the link.
    foreach (['http://localhost/', 'http://meridian.localhost/'] as $url) {
        $this->get($url)->assertOk()->assertSee('/admin/login');
    }
});

it('asks which company on the bare domain, because there is none in the address', function () {
    $this->get('http://localhost/')
        ->assertOk()
        ->assertSee('Sign in to your company')
        ->assertSee('.localhost')
        // No list of client companies anywhere, on purpose: a page that names customers
        // hands over the customer list to anybody who loads it.
        ->assertDontSee('Meridian');
});

it('offers the company by name on that company own address', function () {
    Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);

    // Nothing to ask here, so nothing is asked.
    $this->get('http://meridian.localhost/')
        ->assertOk()
        ->assertSee('Sign in to Meridian Logistics')
        ->assertDontSee('Sign in to your company');
});

it('takes a typed company name to that company sign-in page', function () {
    $this->post('http://localhost/sign-in', ['company' => 'meridian'])
        ->assertRedirect('http://meridian.localhost/admin/login');
});

it('takes a pasted whole address just as well as the first part of one', function () {
    // People paste what they see in the address bar rather than the first word of it.
    $this->post('http://localhost/sign-in', ['company' => 'Meridian.localhost'])
        ->assertRedirect('http://meridian.localhost/admin/login');
});

it('refuses anything that is not the shape of an address, rather than cleaning it up', function () {
    // The typed value becomes part of a host name. A cleaned one can still be a host
    // somebody else owns, which would turn this box into a way of sending a signed-out
    // visitor to a page that looks like ours and is not.
    foreach (['evil.com', 'meridian.evil.com', 'meridian/../vertex', '-meridian', 'me ridian', ''] as $typed) {
        $this->post('http://localhost/sign-in', ['company' => $typed])
            ->assertRedirect('/#sign-in')
            ->assertSessionHasErrors('company');
    }
});

it('names the client company on its own sign-in page', function () {
    Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);

    // Priya has arrived at an address. Whether it is her employer's is the one thing she
    // cannot check anywhere else, so the page says whose it is.
    $this->get('http://meridian.localhost/admin/login')
        ->assertOk()
        ->assertSee('Meridian Logistics');
});

it('sends somebody who reaches the sign-in page with no company behind it back to the question', function () {
    // The bare domain has no client company in it, so no account could be looked up and
    // no password would ever be accepted. Before this the form rendered anyway and took
    // an email address it could only refuse.
    $this->get('http://localhost/admin/login')->assertRedirect('/#sign-in');
});

it('gives the wrong-address page the brand, and keeps it independent of any build', function () {
    $response = $this->get('http://nobody.localhost/');

    $response->assertNotFound()
        ->assertSee('No StartX company at this address')
        // Rendered through the same shared brand component as the other two pages.
        ->assertSee('Summerhill Technologies');
});
