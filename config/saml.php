<?php

return [
    /*
    | Roles a CLIENT connection may ever assign. Everything else is staff-class. A role listed here is still
    | refused for client connections if it carries any staff-only permission (App\Support\Security\StaffPermissions).
    */
    'customer_roles' => ['Customer'],

    /* Allowed clock skew in seconds applied on top of the library's own checks (library constant is 180s). */
    'clock_skew' => 60,

    /* Pending AuthnRequest lifetime (seconds). The IdP round-trip must complete inside this. */
    'request_ttl' => 600,

    /* Metadata fetch timeout (seconds). */
    'metadata_timeout' => 10,

    /* Claim names (Microsoft Entra ID defaults). */
    'claims' => [
        'object_id' => 'http://schemas.microsoft.com/identity/claims/objectidentifier',
        'tenant_id' => 'http://schemas.microsoft.com/identity/claims/tenantid',
        'email' => ['http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress', 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/upn', 'email'],
        'name' => ['http://schemas.microsoft.com/identity/claims/displayname', 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name'],
        'groups' => 'http://schemas.microsoft.com/ws/2008/06/identity/claims/groups',
        'groups_overage' => ['http://schemas.microsoft.com/claims/groups.link'],
    ],
];
