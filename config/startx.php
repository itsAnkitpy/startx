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

];
