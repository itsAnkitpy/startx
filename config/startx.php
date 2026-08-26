<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where a prospective client writes to us
    |--------------------------------------------------------------------------
    |
    | The front page shows a "talk to us" button only when this is set. Left
    | empty on purpose rather than filled with a guess: a wrong address on a
    | public page loses the enquiry silently.
    |
    */

    'contact_email' => env('STARTX_CONTACT_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | Where a document attached to a step is written
    |--------------------------------------------------------------------------
    |
    | A clearance document is evidence and outlives the case, so it goes to
    | ordinary Laravel storage: the local disk while we are building, a cloud
    | disk in production, named here rather than guessed from the default.
    |
    | The local disk's root is `storage/app/private`, which nothing on the web
    | can reach. This must never be set to `public` — that disk exists to be
    | reachable by anybody with the address, and an exit clearance is not.
    |
    */

    'documents_disk' => env('DOCUMENTS_DISK', 'local'),

];
