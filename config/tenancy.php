<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Central domain
    |--------------------------------------------------------------------------
    |
    | Each client company is reached on its own subdomain of this domain, and the
    | subdomain is what puts a tenant in scope before anyone authenticates. A
    | request for the central domain itself has no tenant in scope.
    |
    */

    'central_domain' => env('TENANT_CENTRAL_DOMAIN', 'localhost'),

];
