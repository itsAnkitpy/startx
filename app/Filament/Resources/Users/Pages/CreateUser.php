<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Adding somebody records who they are and nothing about their job, and then lands on
 * their own page so their joining can be recorded as the first dated row of their history.
 *
 * Two acts rather than one on purpose: the form that records their joining is the same form
 * that records every later change, so there is one thing to learn instead of two, and the
 * date a job starts is never asked for in two different places.
 */
class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected static ?string $title = 'Add somebody';
}
