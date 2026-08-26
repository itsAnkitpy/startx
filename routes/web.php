<?php

use App\Http\Controllers\CaseDocumentController;
use App\Http\Controllers\StepLinkController;
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

/*
| The one way into this product for somebody who has no account: a candidate, or a leaver
| whose sign-in has already been switched off. The token in the address is the whole
| permission — there is no session, no queue and no resolved set behind an external step —
| and it is checked again on the server every time one of these three is asked for.
|
| Outside the panel and outside every sign-in, because the person opening it cannot sign
| in. Still inside the client company's own subdomain, so the wall that scopes every query
| is already in place and a token can only ever open a step at the address it was sent for.
|
| Asking for a new link is held to a few tries an hour. It sends mail to an address the
| person pressing it cannot choose, which is exactly the shape of button that gets used to
| flood somebody's inbox if it is left open.
*/
Route::get('step/{token}', [StepLinkController::class, 'show'])->name('step-link');
Route::post('step/{token}', [StepLinkController::class, 'submit'])->name('step-link.submit');
Route::post('step/{token}/again', [StepLinkController::class, 'again'])
    ->middleware('throttle:5,60')
    ->name('step-link.again');

/*
| Opening a document somebody attached to a step.
|
| Signed in, on the client company's own subdomain, and checked again on every request:
| whether this person has any business with this case at all is asked here rather than
| answered once into an address that then works for whoever is holding it. The file lives
| on a disk nothing on the web can reach, and this is the only way to it.
|
| The address names a case, a step and a question — never a path — so there is nothing in
| it to edit into somebody else's file.
*/
Route::get('cases/{case}/documents/{sequence}/{question}', [CaseDocumentController::class, 'show'])
    ->middleware('auth')
    ->whereNumber('case')
    ->whereNumber('sequence')
    ->where('question', '[a-z][a-z0-9_]*')
    ->name('case-document');
