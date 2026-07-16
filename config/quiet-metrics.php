<?php

return [

    // Clé publique du site (qm_pub_…), panneau « Installation » de l'espace membre.
    'public_key' => env('QUIET_METRICS_PUBLIC_KEY'),

    // Clé secrète (qm_sec_…) : active le mode signé, l'IP/UA du visiteur
    // transmis par le serveur font foi (docs/05-api-et-sdk.md §1).
    'secret_key' => env('QUIET_METRICS_SECRET_KEY'),

    'endpoint' => env('QUIET_METRICS_ENDPOINT', 'https://app.quietmetrics.dev/api/v1/collect'),

    // À activer si l'app est derrière un reverse proxy / CDN (X-Forwarded-For).
    'trust_proxy_headers' => (bool) env('QUIET_METRICS_TRUST_PROXY', false),

];
