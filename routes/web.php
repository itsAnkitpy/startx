<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
| The "sign in" button on the front page. On a client company's own address it goes
| straight to that company's sign-in page. On the bare domain there is no company yet, so
| the page asks which one and this is what turns the answer into an address.
|
| Nothing is looked up here on purpose. Checking whether the company exists before
| redirecting would make this box a way of asking "is this company a customer?" and
| getting an answer, over and over. Sending the visitor on and letting the existing
| wrong-address page answer discloses exactly what typing the address by hand already
| does, and is less code.
*/
Route::post('sign-in', function (Request $request) {
    $central = strtolower((string) config('tenancy.central_domain'));
    $company = strtolower(trim((string) $request->input('company')));

    // People paste the whole address rather than the first part of it.
    if (str_ends_with($company, '.'.$central)) {
        $company = substr($company, 0, -strlen($central) - 1);
    }

    // What is typed here becomes part of a host name, so it is held to the shape of one
    // and refused rather than cleaned up. A cleaned value can still be a host somebody
    // else controls, which would turn this box into a way of sending a signed-out
    // visitor anywhere at all.
    if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $company) !== 1) {
        return redirect('/#sign-in')
            ->withInput()
            ->withErrors(['company' => 'Type just the first part of your address — the bit before .'.$central.'.']);
    }

    return redirect()->away($request->getScheme().'://'.$company.'.'.$central.'/admin/login');
})->name('sign-in');
