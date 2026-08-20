<?php

namespace App\Filament\Auth;

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
