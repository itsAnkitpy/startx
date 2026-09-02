<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;

/**
 * One person: their details, and underneath them their job history and the tax and bank
 * numbers on file.
 *
 * There is no delete action here, unlike the page Filament generates. A person's record is
 * the evidence behind their exit and their settlement, and the policy refuses a delete at
 * every permission level — an account that should no longer sign in is switched off on the
 * form.
 */
class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    public function getTitle(): string
    {
        return $this->personBeingKept()->name;
    }

    public function getBreadcrumb(): string
    {
        return $this->personBeingKept()->name;
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    private function personBeingKept(): User
    {
        /** @var User $person */
        $person = $this->getRecord();

        return $person;
    }
}
