<?php

namespace App\Filament\Auth;

use App\Tenancy\TenantContext;
use Filament\Auth\Pages\Login as BaseLogin;
use SensitiveParameter;

/**
 * The sign-in page, changed in exactly one place: the address column is
 * `work_email`, not `email`, so that is what gets looked up.
 *
 * The form field keeps its own name, which leaves Filament's validation, its
 * autofill and its throttling untouched.
 *
 * There is no need to narrow the lookup to a client company here. The subdomain has
 * already put one in scope before this page runs, so the person model is narrowed to
 * it and the database refuses anything outside it.
 */
class Login extends BaseLogin
{
    /**
     * The branded shell, and a layout that hands it the whole viewport instead of
     * Filament's centred card. Only the surroundings change: the form inside is still
     * {@see BaseLogin::content()}, so the throttling, the failed-attempt messages,
     * remember-me and the two-factor challenge stay Filament's.
     */
    protected string $view = 'filament.auth.login';

    protected static string $layout = 'filament.auth.login-layout';

    /**
     * Signing in only happens on a client company's own address. On the bare domain
     * there is no company in scope, so no account can be looked up and nobody could get
     * through — the form would take an email address and refuse it forever.
     *
     * So the "sign in" button on the front page points here on every address, and here is
     * where the bare domain is turned back: to the panel on that page that asks which
     * company the person belongs to, which is the question that has to be answered before
     * a sign-in form means anything.
     */
    public function mount(): void
    {
        if (TenantContext::id() === null) {
            redirect('/#sign-in');

            return;
        }

        parent::mount();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        return [
            'work_email' => $data['email'],
            'password' => $data['password'],
        ];
    }
}
