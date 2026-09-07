<?php

namespace App\Filament\Resources\NotificationTemplates\Pages;

use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use Filament\Resources\Pages\ListRecords;

/**
 * No header actions at all, which is the point: there is nothing to add here.
 */
class ListNotificationTemplates extends ListRecords
{
    protected static string $resource = NotificationTemplateResource::class;

    protected static ?string $title = 'Messages we send';

    public function getSubheading(): string
    {
        return 'These are the emails your staff and your leavers receive. You can change what any of '
            .'them says. You cannot add a message or stop one being sent.';
    }
}
