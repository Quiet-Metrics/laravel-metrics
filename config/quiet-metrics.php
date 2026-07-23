<?php

return [

    // Clé publique du site (qm_pub_…), panneau « Installation » de l'espace membre.
    'public_key' => env('QUIET_METRICS_PUBLIC_KEY'),

    // Clé secrète (qm_sec_…) : INDISPENSABLE en envoi serveur. Elle active le
    // mode signé, seul cas où l'IP/UA du visiteur transmis font foi ; sans
    // elle, tous les hits porteraient l'IP de ce serveur (un seul visiteur
    // compté). Docs/05-api-et-sdk.md §1.
    'secret_key' => env('QUIET_METRICS_SECRET_KEY'),

    'endpoint' => env('QUIET_METRICS_ENDPOINT', 'https://quietmetrics.dev/api/v1/collect'),

    // À activer si l'app est derrière un reverse proxy / CDN (X-Forwarded-For).
    'trust_proxy_headers' => (bool) env('QUIET_METRICS_TRUST_PROXY', false),

];
