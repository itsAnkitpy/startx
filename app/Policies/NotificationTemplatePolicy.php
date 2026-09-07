<?php

namespace App\Policies;

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Models\NotificationTemplate;
use App\Models\User;

/**
 * Every question is about an action, never about a role.
 *
 * No part of the structure is passed to any of these. A message's wording belongs to the
 * whole client company — the same words go to a leaver in Shimla and one in Pune — so
 * there is nothing for a grant over one branch to narrow, and narrowing it would be
 * wrong rather than merely unnecessary: somebody responsible for one branch would be
 * rewording what every branch sends.
 */
class NotificationTemplatePolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->allowsEverywhere($user, Permission::ManageNotificationTemplate);
    }

    public function view(User $user, NotificationTemplate $template): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Nobody, ever. The catalogue is seeded when a client company is created and a client
     * cannot invent a message, because a message nothing sends is words nobody reads.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, NotificationTemplate $template): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Nobody, ever. {@see NotificationTemplate} throws rather than deleting: a step sends
     * a message by naming it, so a client with no wording for it is a step that sends
     * nothing. There is no switching off to fall back on either, which is this module's
     * one flat refusal — a person chooses when they are told, never whether.
     *
     * Answered here as well as refused there so the screen never offers the button, and
     * the panel's strict authorization has a method to call rather than throwing.
     */
    public function delete(User $user, NotificationTemplate $template): bool
    {
        return false;
    }
}
