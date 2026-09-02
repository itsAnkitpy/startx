<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Filament\Resources\Roles\RoleResource;
use App\Models\Role;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * One role: what it is called, what it can do, and — underneath — who holds it.
 *
 * The tick-boxes are not a column on the role, they are a row per action, so the ticks are
 * read into the form when the page opens and written back after it saves. Both halves loop
 * over the same grouped list the form draws from, so a name added by a later module needs
 * nothing here.
 */
class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /** @var list<string> */
    private array $chosenActions = [];

    public function getTitle(): string
    {
        return (string) $this->getRecord()->name;
    }

    /**
     * Said only to somebody reading the tick-boxes without being able to move them, so
     * that a page with no Save button reads as a rule rather than as a broken screen.
     */
    public function getSubheading(): ?string
    {
        return $this->canChangeWhatItCanDo()
            ? null
            : 'You can hand this role out, and take it away, over your own part of the company — the list underneath. Changing what it can do covers everybody who holds it anywhere, so it needs a role covering your whole company.';
    }

    protected function getHeaderActions(): array
    {
        return [
            // Hidden on a role that came with the company: a seeded process points at it,
            // and deleting one would take every grant with it through the database. The
            // policy refuses it and so does the record, so this is the third of three.
            DeleteAction::make()
                ->label('Delete this role')
                ->modalHeading('Delete this role')
                ->modalDescription('Everybody who holds it loses it, and they lose whatever it let them do. Their own records are untouched. There is no undo.')
                ->modalSubmitActionLabel('Delete it'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $held = $this->getRecord()->permissionNames();

        foreach (Permission::describedForAClient() as $group) {
            $data['actions'][$group['key']] = array_values(
                array_intersect(array_keys($group['actions']), $held),
            );
        }

        return $data;
    }

    /**
     * The ticks are not columns on the role, so they come out of the data before it is
     * saved and are written to their own rows afterwards.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $ticked = is_array($data['actions'] ?? null) ? $data['actions'] : [];

        $this->chosenActions = array_merge([], ...array_map(
            fn (mixed $chosen): array => is_array($chosen) ? array_values($chosen) : [],
            array_values($ticked),
        ));

        unset($data['actions']);

        return $data;
    }

    /**
     * Somebody responsible for roles in one branch alone opens this page to hand a role
     * out over that branch, and the tick-boxes are drawn for them read-only. This is what
     * refuses the write, because Filament's own note says a disabled field can be
     * re-enabled from the browser and the check belongs on the server.
     */
    protected function canChangeWhatItCanDo(): bool
    {
        return auth()->user()?->can('changeWhatItCanDo', $this->getRecord()) ?? false;
    }

    protected function getFormActions(): array
    {
        return $this->canChangeWhatItCanDo() ? parent::getFormActions() : [];
    }

    protected function afterSave(): void
    {
        /** @var Role $role */
        $role = $this->getRecord();

        if ($this->canChangeWhatItCanDo()) {
            $role->keepOnlyTheseActions($this->chosenActions);
        }

        // Answers are remembered for the life of one request, so the person who has just
        // changed a role must not be told what it could do a moment ago.
        app(PermissionResolver::class)->forget();
    }
}
