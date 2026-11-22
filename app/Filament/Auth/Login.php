<?php

namespace App\Filament\Auth;

use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Tenancy\TenantContext;
use Filament\Auth\Pages\Login as BaseLogin;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
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

    /**
     * An account that is switched off is told so, once its own password has been typed.
     *
     * Every sign-in looks people up through a query that leaves out an account that is off
     * ({@see AppServiceProvider::boot()}), so Filament finds nobody and told Priya, the
     * morning after her last working day, that her right password did not match. She would
     * try a reset or ring IT. Somebody without the password still reads Filament's own
     * sentence, so a guesser learns nothing, and this runs inside the same timebox as every
     * other refusal.
     */
    protected function throwFailureValidationException(): never
    {
        $person = User::query()
            ->where('work_email', (string) ($this->data['email'] ?? ''))
            ->where('active', false)
            ->first();

        if ($person !== null && Hash::check((string) ($this->data['password'] ?? ''), (string) $person->password)) {
            throw ValidationException::withMessages([
                'data.email' => 'Your account is switched off, so you cannot sign in. If you have left, anything still owed to you goes to your personal email. If you have not, ask HR to turn it back on.',
            ]);
        }

        parent::throwFailureValidationException();
    }
}
